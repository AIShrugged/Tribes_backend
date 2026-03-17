import argparse
import json
import pathlib
import socket
import sys
import uuid
from urllib.parse import urlparse
from typing import Any

import requests


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--artifacts-dir", required=True)
    return parser.parse_args()


def write_result(path: str, payload: dict[str, Any]) -> None:
    pathlib.Path(path).parent.mkdir(parents=True, exist_ok=True)
    pathlib.Path(path).write_text(json.dumps(payload, ensure_ascii=False, indent=2))


def call_llm(gateway: dict[str, Any], messages: list[dict[str, Any]], system_prompt: str | None, max_tokens: int = 2048, include_tools: bool = True) -> dict[str, Any]:
    response = requests.post(
        f"{gateway['base_url']}{gateway['llm_completion_path']}",
        headers={"X-Sandbox-Run-Token": gateway["token"]},
        json={
            "messages": messages,
            "system_prompt": system_prompt,
            "max_tokens": max_tokens,
            "include_tools": include_tools,
        },
        timeout=120,
    )

    if response.status_code >= 400:
        raise RuntimeError(
            f"Sandbox LLM completion failed with HTTP {response.status_code}: {response.text}"
        )

    data = response.json()
    if not data.get("success"):
        raise RuntimeError(data.get("message", "Sandbox LLM completion failed"))

    result = data.get("data", {})
    if not result.get("success"):
        raise RuntimeError("Sandbox LLM completion failed")

    message = result.get("message")
    if not isinstance(message, dict):
        raise RuntimeError("Sandbox LLM completion did not return a message")

    return message


def host_matches(host: str, allowed_hosts: list[str]) -> bool:
    normalized = host.strip().lower().rstrip(".")
    for allowed in allowed_hosts:
        candidate = allowed.strip().lower().rstrip(".")
        if not candidate:
            continue
        if candidate.startswith("*."):
            suffix = candidate[1:]
            if normalized.endswith(suffix) and normalized != suffix.lstrip("."):
                return True
        elif normalized == candidate:
            return True

    return False


def enforce_url(url: str, allowed_hosts: list[str], allowed_schemes: list[str]) -> None:
    parsed = urlparse(url)
    scheme = (parsed.scheme or "").lower()
    host = (parsed.hostname or "").lower()

    if scheme not in allowed_schemes:
        raise RuntimeError(f"Outbound scheme '{scheme or '<empty>'}' is not allowed")

    if host == "":
        raise RuntimeError("Outbound URL host is missing")

    if not host_matches(host, allowed_hosts):
        raise RuntimeError(f"Outbound host '{host}' is not allowed")


def install_network_guard(network_policy: dict[str, Any]) -> None:
    allowed_hosts = [host for host in network_policy.get("allowed_hosts", []) if isinstance(host, str)]
    allowed_schemes = [scheme.lower() for scheme in network_policy.get("allowed_schemes", ["http", "https"]) if isinstance(scheme, str)]

    original_request = requests.sessions.Session.request
    original_getaddrinfo = socket.getaddrinfo
    original_create_connection = socket.create_connection

    def guarded_request(self, method, url, *args, **kwargs):  # noqa: ANN001
        enforce_url(url, allowed_hosts, allowed_schemes)
        return original_request(self, method, url, *args, **kwargs)

    def guarded_getaddrinfo(host, *args, **kwargs):  # noqa: ANN001
        if isinstance(host, str) and host != "":
            if not host_matches(host, allowed_hosts):
                raise RuntimeError(f"DNS resolution for host '{host}' is not allowed")
        return original_getaddrinfo(host, *args, **kwargs)

    def guarded_create_connection(address, *args, **kwargs):  # noqa: ANN001
        host = address[0] if isinstance(address, tuple) and address else None
        if isinstance(host, str) and host != "":
            if not host_matches(host, allowed_hosts):
                raise RuntimeError(f"Socket connection to host '{host}' is not allowed")
        return original_create_connection(address, *args, **kwargs)

    requests.sessions.Session.request = guarded_request
    socket.getaddrinfo = guarded_getaddrinfo
    socket.create_connection = guarded_create_connection


def build_agent_system_prompt(payload: dict[str, Any]) -> str:
    profile = payload.get("agent_profile", {}) or {}
    task = payload.get("task", {}) or {}
    user = payload.get("user", {}) or {}
    memory = payload.get("agent_memory", []) or []

    profile_prompt = profile.get("system_prompt") or ""
    memory_json = json.dumps(memory, ensure_ascii=False, indent=2)
    task_payload_json = json.dumps(task.get("input_payload", {}), ensure_ascii=False, indent=2)
    tools_json = json.dumps(payload.get("tools", []), ensure_ascii=False, indent=2)

    return f"""{profile_prompt}

You are an autonomous sandboxed agent run.

Current task:
- task_id: {task.get("id")}
- run_id: {task.get("run_id")}
- task_name: {task.get("name")}
- user_id: {user.get("id")}

Task payload:
```json
{task_payload_json}
```

Persistent agent memory available to you:
```json
{memory_json}
```

Tools available through the host gateway:
```json
{tools_json}
```

Rules:
- Use tools when needed to inspect data and verify facts.
- Resolve the repository branch before deep inspection. Prefer `dev`, then `develop`, then the default branch.
- Record which branch and latest commit SHA you inspected, and include that as a memory candidate.
- Do not invent repository facts, architecture, CI, or integrations that you did not verify.
- Only state facts you observed directly from tool results or repository responses.
- Prefer concise, high-signal facts worth persisting.
- Do not say or imply that memory was updated unless you return non-empty `memory_candidates`.
- Your final answer must be valid JSON only. Do not wrap it in markdown. Do not add prose before or after the JSON object.
- Your task is not complete until you return exactly one valid JSON object with the required fields.
- If you output plain text, markdown, or commentary instead of JSON, the run will fail.
- When the task is complete, return a final answer as valid JSON with this exact shape:
  {{
    "output": "human-readable result",
    "summary": "short summary",
    "memory_candidates": [
      {{
        "kind": "fact",
        "content": "verified fact",
        "priority": 50
      }}
    ]
  }}
- `memory_candidates` must contain only validated facts. If you found nothing reliable, return an empty array.
- If blocked, return JSON with empty `memory_candidates` and explain the blocker in `output` and `summary`.
"""


def normalize_final_content(content: str) -> dict[str, Any]:
    content = (content or "").strip()
    if content == "":
        raise RuntimeError("Agent returned an empty final response instead of the required JSON object")

    if content.startswith("```"):
        lines = content.splitlines()
        if len(lines) >= 3 and lines[0].startswith("```") and lines[-1].strip() == "```":
            content = "\n".join(lines[1:-1]).strip()

    try:
        parsed = json.loads(content)
    except Exception as exc:  # noqa: BLE001
        raise RuntimeError(
            f"Agent final response must be valid JSON with output, summary, memory_candidates. Raw response: {content}"
        ) from exc

    if not isinstance(parsed, dict):
        raise RuntimeError("Agent final response JSON must be an object")

    if "output" not in parsed and "summary" not in parsed and "memory_candidates" not in parsed:
        repository = parsed.get("repository")
        description = parsed.get("description")
        architecture = parsed.get("architecture")
        memory_tiers = parsed.get("memory_tiers")
        components = parsed.get("components")
        data_layer = parsed.get("data_layer")
        ai_integration = parsed.get("ai_integration")
        roadmap = parsed.get("roadmap")
        overall_design = parsed.get("overall_design")

        derived_output_parts: list[str] = []
        if isinstance(repository, str) and repository.strip() != "":
            derived_output_parts.append(f"Repository analyzed: {repository.strip()}.")
        system_name = parsed.get("system_name")
        if isinstance(system_name, str) and system_name.strip() != "":
            derived_output_parts.append(f"System analyzed: {system_name.strip()}.")
        if isinstance(description, str) and description.strip() != "":
            derived_output_parts.append(description.strip())
        if isinstance(overall_design, str) and overall_design.strip() != "":
            derived_output_parts.append(overall_design.strip())

        derived_summary = None
        if isinstance(description, str) and description.strip() != "":
            derived_summary = description.strip()
        elif isinstance(architecture, dict):
            overview = architecture.get("overview")
            if isinstance(overview, str) and overview.strip() != "":
                derived_summary = overview.strip()
        elif isinstance(overall_design, str) and overall_design.strip() != "":
            derived_summary = overall_design.strip()

        derived_candidates: list[dict[str, Any]] = []

        def add_candidate(kind: str, text: str, priority: int = 70) -> None:
            cleaned = text.strip()
            if cleaned == "":
                return
            derived_candidates.append({
                "kind": kind,
                "content": cleaned,
                "priority": priority,
            })

        if isinstance(description, str) and description.strip() != "":
            add_candidate("architecture_fact", description, 85)

        if isinstance(architecture, dict):
            overview = architecture.get("overview")
            if isinstance(overview, str):
                add_candidate("architecture_fact", overview, 90)

            architecture_components = architecture.get("components")
            if isinstance(architecture_components, list):
                component_names = [item.strip() for item in architecture_components if isinstance(item, str) and item.strip() != ""]
                if component_names:
                    add_candidate("architecture_fact", f"Core architecture components include: {', '.join(component_names)}.", 75)

            for field, kind in [
                ("insightExtractionPipeline", "workflow_fact"),
                ("profileEvolution", "workflow_fact"),
                ("relationshipsTracking", "workflow_fact"),
            ]:
                value = architecture.get(field)
                if isinstance(value, str):
                    add_candidate(kind, value, 75)

            data_storage = architecture.get("dataStorage")
            if isinstance(data_storage, dict):
                database = data_storage.get("database")
                features = data_storage.get("features")
                if isinstance(database, str) and database.strip() != "":
                    text = f"Data storage uses {database.strip()}."
                    if isinstance(features, str) and features.strip() != "":
                        text = f"{text} {features.strip()}"
                    add_candidate("tooling_fact", text, 80)

            integration = architecture.get("integration")
            if isinstance(integration, dict):
                telegram_agent = integration.get("telegramAgent")
                if isinstance(telegram_agent, str):
                    add_candidate("integration_fact", f"Telegram agent integration: {telegram_agent}", 70)

                ai = integration.get("AI")
                if isinstance(ai, dict):
                    llm_gateway = ai.get("LLMgateway")
                    model = ai.get("model")
                    ai_parts = []
                    if isinstance(llm_gateway, str) and llm_gateway.strip() != "":
                        ai_parts.append(f"LLM gateway: {llm_gateway.strip()}")
                    if isinstance(model, str) and model.strip() != "":
                        ai_parts.append(f"model: {model.strip()}")
                    if ai_parts:
                        add_candidate("tooling_fact", "AI integration uses " + ", ".join(ai_parts) + ".", 75)

            apis = architecture.get("APIs")
            if isinstance(apis, list):
                api_items = [item.strip() for item in apis if isinstance(item, str) and item.strip() != ""]
                if api_items:
                    add_candidate("architecture_fact", f"Exposed API areas include: {', '.join(api_items)}.", 65)

        tiers_source = memory_tiers if isinstance(memory_tiers, list) else (architecture.get("memoryTiers") if isinstance(architecture, dict) else None)
        if isinstance(tiers_source, list):
            for tier in tiers_source:
                if not isinstance(tier, dict):
                    continue
                tier_name = tier.get("name")
                tier_description = tier.get("description")
                tier_type = tier.get("type")
                tier_contents = tier.get("contents")
                tier_insights = tier.get("insights")
                tier_ttl = tier.get("ttl")

                parts = []
                if isinstance(tier_name, str) and tier_name.strip() != "":
                    parts.append(tier_name.strip())
                if isinstance(tier_type, str) and tier_type.strip() != "":
                    parts.append(f"type: {tier_type.strip()}")
                if isinstance(tier_ttl, str) and tier_ttl.strip() != "":
                    parts.append(f"ttl: {tier_ttl.strip()}")
                if isinstance(tier_description, str) and tier_description.strip() != "":
                    parts.append(tier_description.strip())
                if isinstance(tier_contents, list):
                    contents = [item.strip() for item in tier_contents if isinstance(item, str) and item.strip() != ""]
                    if contents:
                        parts.append("contents: " + ", ".join(contents))
                if isinstance(tier_insights, list):
                    insights = [item.strip() for item in tier_insights if isinstance(item, str) and item.strip() != ""]
                    if insights:
                        parts.append("insights: " + ", ".join(insights))
                if parts:
                    add_candidate("architecture_fact", "; ".join(parts), 80)

        if isinstance(components, dict):
            models = components.get("models")
            if isinstance(models, list):
                model_names = [item.strip() for item in models if isinstance(item, str) and item.strip() != ""]
                if model_names:
                    add_candidate("architecture_fact", f"Core data models include: {', '.join(model_names)}.", 75)

            services = components.get("services")
            if isinstance(services, list):
                service_summaries: list[str] = []
                for service in services:
                    if isinstance(service, str) and service.strip() != "":
                        service_summaries.append(service.strip())
                    elif isinstance(service, dict):
                        name = service.get("name")
                        description_value = service.get("description")
                        if isinstance(name, str) and name.strip() != "":
                            if isinstance(description_value, str) and description_value.strip() != "":
                                service_summaries.append(f"{name.strip()} ({description_value.strip()})")
                            else:
                                service_summaries.append(name.strip())
                if service_summaries:
                    add_candidate("architecture_fact", f"Key services include: {', '.join(service_summaries)}.", 70)

        if isinstance(data_layer, dict):
            database = data_layer.get("database")
            architecture_value = data_layer.get("architecture")
            parts = []
            if isinstance(database, str) and database.strip() != "":
                parts.append(f"Database: {database.strip()}")
            if isinstance(architecture_value, str) and architecture_value.strip() != "":
                parts.append(f"Data layer architecture: {architecture_value.strip()}")
            if parts:
                add_candidate("tooling_fact", ". ".join(parts) + ".", 75)

        if isinstance(ai_integration, dict):
            gateway_value = ai_integration.get("gateway")
            model_value = ai_integration.get("model")
            parts = []
            if isinstance(gateway_value, str) and gateway_value.strip() != "":
                parts.append(f"gateway: {gateway_value.strip()}")
            if isinstance(model_value, str) and model_value.strip() != "":
                parts.append(f"model: {model_value.strip()}")
            if parts:
                add_candidate("tooling_fact", "AI integration uses " + ", ".join(parts) + ".", 75)

        if isinstance(roadmap, list):
            roadmap_items = [item.strip() for item in roadmap if isinstance(item, str) and item.strip() != ""]
            if roadmap_items:
                add_candidate("roadmap_fact", f"Roadmap mentions: {', '.join(roadmap_items)}.", 50)

        if isinstance(overall_design, str) and overall_design.strip() != "":
            add_candidate("architecture_fact", overall_design, 70)

        parsed = {
            "output": " ".join(derived_output_parts).strip() or "Repository analysis completed.",
            "summary": derived_summary or "Extracted structured repository architecture facts.",
            "memory_candidates": derived_candidates,
        }

    output = parsed.get("output")
    summary = parsed.get("summary")
    memory_candidates = parsed.get("memory_candidates")

    if not isinstance(output, str):
        output = next((
            candidate for candidate in [
                parsed.get("final_answer"),
                parsed.get("answer"),
                parsed.get("result"),
                parsed.get("message"),
                summary,
            ]
            if isinstance(candidate, str) and candidate.strip() != ""
        ), None)

    if not isinstance(summary, str):
        summary = next((
            candidate for candidate in [
                parsed.get("summary_text"),
                parsed.get("short_summary"),
                output,
            ]
            if isinstance(candidate, str) and candidate.strip() != ""
        ), None)

    if not isinstance(memory_candidates, list):
        memory_candidates = next((
            candidate for candidate in [
                parsed.get("memories"),
                parsed.get("memory_facts"),
                parsed.get("facts"),
            ]
            if isinstance(candidate, list)
        ), None)

    if memory_candidates is None:
        memory_candidates = []

    normalized_candidates: list[dict[str, Any]] = []
    for item in memory_candidates:
        if isinstance(item, dict):
            candidate_content = item.get("content")
            if isinstance(candidate_content, str) and candidate_content.strip() != "":
                normalized_candidates.append({
                    "kind": str(item.get("kind") or "fact"),
                    "content": candidate_content.strip(),
                    "priority": int(item.get("priority") or 50),
                })
        elif isinstance(item, str) and item.strip() != "":
            normalized_candidates.append({
                "kind": "fact",
                "content": item.strip(),
                "priority": 50,
            })

    memory_candidates = normalized_candidates

    if not isinstance(output, str) or output.strip() == "":
        raise RuntimeError(f"Agent final response JSON must contain a non-empty output string. Raw response: {content}")

    if not isinstance(summary, str) or summary.strip() == "":
        summary = output

    lower_output = output.lower()
    lower_summary = summary.lower()
    if len(memory_candidates) == 0 and ("memory updated" in lower_output or "memory updated" in lower_summary):
        raise RuntimeError("Agent claimed that memory was updated but returned empty memory_candidates")

    return {
        "output": output,
        "summary": summary,
        "memory_candidates": memory_candidates,
    }


def repair_final_content(gateway: dict[str, Any], raw_content: str) -> dict[str, Any]:
    repair_prompt = """You are a response normalizer.

Your only job is to convert the provided agent output into a valid JSON object with exactly these fields:
- output: string
- summary: string
- memory_candidates: array

Rules:
- Return JSON only. No markdown. No explanations.
- Preserve factual content from the original response.
- Do not invent new facts.
- If the original response contains useful factual findings, convert them into memory_candidates.
- If the original response claims that memory was updated, convert the factual claims into memory_candidates.
- Each memory candidate must be an object with:
  - kind: string
  - content: string
  - priority: integer 0-100
- Always return all three top-level fields, even if some are empty.
- `output` must be a readable final answer for the user.
- `summary` must be a short summary string.
- `memory_candidates` must be an array.
- If the source text contains architecture, tooling, workflow, or repository facts, extract them into memory_candidates.
- If no reliable memory facts are present, return an empty memory_candidates array.

Example valid output:
{
  "output": "Repository uses Laravel and an Insight System with hierarchical memory.",
  "summary": "Extracted key architecture facts from the repository.",
  "memory_candidates": [
    {
      "kind": "architecture_fact",
      "content": "Repository uses Laravel 12 as the backend framework.",
      "priority": 90
    }
  ]
}
"""

    repair_messages = [
        {
            "role": "user",
            "content": f"Normalize this agent output into the required JSON object:\n\n{raw_content}",
        }
    ]

    strict_repair_prompt = repair_prompt + """

You failed to return a valid object previously.
Return a JSON object right now with exactly:
{
  "output": "...",
  "summary": "...",
  "memory_candidates": []
}
Do not omit fields. Do not add extra top-level fields.
"""

    prompts = [repair_prompt, strict_repair_prompt]
    last_error: Exception | None = None

    for prompt in prompts:
        repaired_message = call_llm(gateway, repair_messages, prompt, max_tokens=1600, include_tools=False)
        tool_calls = repaired_message.get("tool_calls") or []
        if isinstance(tool_calls, list) and tool_calls:
            last_error = RuntimeError("Repair pass attempted tool calls instead of returning final JSON")
            continue

        repaired_content = repaired_message.get("content")
        if not isinstance(repaired_content, str):
            last_error = RuntimeError("Repair pass did not return textual JSON content")
            continue

        try:
            return normalize_final_content(repaired_content)
        except RuntimeError as exc:
            last_error = exc
            continue

    if last_error is not None:
        raise last_error

    raise RuntimeError("Repair pass failed to produce a valid JSON response")


def extract_memory_candidates(gateway: dict[str, Any], output: str, summary: str) -> list[dict[str, Any]]:
    extraction_prompt = """You extract persistent memory facts from an agent result.

Return JSON only with this exact shape:
{
  "memory_candidates": [
    {
      "kind": "fact",
      "content": "verified fact",
      "priority": 50
    }
  ]
}

Rules:
- Extract only concrete, repository-specific facts.
- Prefer architecture_fact, tooling_fact, workflow_fact, integration_fact, repo_revision_fact, roadmap_fact.
- If branch or commit SHA was mentioned, include it as repo_revision_fact.
- Do not invent facts that were not present.
- If there are no reliable facts, return an empty array.
"""

    extraction_message = call_llm(gateway, [
        {
            "role": "user",
            "content": f"Output:\n{output}\n\nSummary:\n{summary}\n\nExtract memory candidates.",
        }
    ], extraction_prompt, max_tokens=1400, include_tools=False)

    tool_calls = extraction_message.get("tool_calls") or []
    if isinstance(tool_calls, list) and tool_calls:
        raise RuntimeError("Memory extraction pass attempted tool calls instead of returning JSON")

    extraction_content = extraction_message.get("content")
    if not isinstance(extraction_content, str):
        return []

    try:
        parsed = json.loads(extraction_content)
    except Exception:
        return []

    if not isinstance(parsed, dict):
        return []

    candidates = parsed.get("memory_candidates")
    if not isinstance(candidates, list):
        return []

    normalized: list[dict[str, Any]] = []
    for item in candidates:
        if isinstance(item, dict):
            candidate_content = item.get("content")
            if isinstance(candidate_content, str) and candidate_content.strip() != "":
                normalized.append({
                    "kind": str(item.get("kind") or "fact"),
                    "content": candidate_content.strip(),
                    "priority": int(item.get("priority") or 50),
                })

    return normalized


def run_agent_mode(payload: dict[str, Any], args: argparse.Namespace) -> dict[str, Any]:
    gateway = payload.get("gateway", {})
    task = payload.get("task", {})
    system_prompt = build_agent_system_prompt(payload)
    messages: list[dict[str, Any]] = [
        {"role": "user", "content": task.get("prompt") or "Execute the task."}
    ]

    max_iterations = int(task.get("max_iterations") or 8)

    def tool_call(tool_name: str, arguments: dict[str, Any] | None = None) -> Any:
        response = requests.post(
            f"{gateway['base_url']}{gateway['tool_call_path']}",
            headers={"X-Sandbox-Run-Token": gateway["token"]},
            json={
                "tool_name": tool_name,
                "arguments": arguments or {},
            },
            timeout=60,
        )

        if response.status_code >= 400:
            raise RuntimeError(
                f"Sandbox tool call '{tool_name}' failed with HTTP {response.status_code}: {response.text}"
            )

        data = response.json()
        if not data.get("success"):
            raise RuntimeError(data.get("message", "Sandbox tool call failed"))

        return data.get("data", {}).get("result")

    for _ in range(max_iterations):
        assistant_message = call_llm(gateway, messages, system_prompt)
        messages.append(assistant_message)

        tool_calls = assistant_message.get("tool_calls") or []
        if isinstance(tool_calls, list) and tool_calls:
            for tool_call_item in tool_calls:
                function = tool_call_item.get("function", {}) or {}
                tool_name = function.get("name")
                arguments_raw = function.get("arguments") or "{}"
                try:
                    arguments = json.loads(arguments_raw)
                    if not isinstance(arguments, dict):
                        arguments = {}
                except Exception:  # noqa: BLE001
                    arguments = {}

                tool_result = tool_call(tool_name, arguments)
                messages.append({
                    "role": "tool",
                    "tool_call_id": tool_call_item.get("id") or str(uuid.uuid4()),
                    "content": json.dumps(tool_result, ensure_ascii=False),
                })

            continue

        content = assistant_message.get("content")
        if isinstance(content, str):
            try:
                normalized = normalize_final_content(content)
            except RuntimeError:
                normalized = repair_final_content(gateway, content)

            if len(normalized["memory_candidates"]) == 0 and (normalized["output"].strip() != "" or normalized["summary"].strip() != ""):
                extracted = extract_memory_candidates(gateway, normalized["output"], normalized["summary"])
                if extracted:
                    normalized["memory_candidates"] = extracted

            return normalized

    raise RuntimeError("Agent loop reached max iterations without a final answer")


def main() -> int:
    args = parse_args()
    payload = json.loads(pathlib.Path(args.input).read_text())

    network_policy = payload.get("network_policy", {})

    install_network_guard(network_policy)

    try:
        final_result = run_agent_mode(payload, args)
        result_payload = {
            "success": True,
            "output": final_result["output"],
            "summary": final_result["summary"],
            "memory_candidates": final_result["memory_candidates"],
        }
    except Exception as exc:  # noqa: BLE001
        write_result(args.output, {
            "success": False,
            "error": str(exc),
        })
        return 1

    write_result(args.output, result_payload)

    return 0


if __name__ == "__main__":
    sys.exit(main())
