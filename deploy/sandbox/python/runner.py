from __future__ import annotations

import argparse
import json
import pathlib
import socket
import sys
import uuid
from typing import Any
from urllib.parse import urlparse

import requests

from local_tools import execute_local_tool, local_tool_names, local_tools
from result_utils import build_fallback_result, extract_memory_candidates, normalize_final_content, repair_final_content
from runtime_state import SandboxRuntimeState


STATE = SandboxRuntimeState()


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--artifacts-dir", required=True)
    return parser.parse_args()


def write_result(path: str, payload: dict[str, Any]) -> None:
    pathlib.Path(path).parent.mkdir(parents=True, exist_ok=True)
    pathlib.Path(path).write_text(json.dumps(payload, ensure_ascii=False, indent=2))


def call_llm(
    gateway: dict[str, Any],
    messages: list[dict[str, Any]],
    system_prompt: str | None,
    max_tokens: int = 2048,
    include_tools: bool = True,
    extra_tools: list[dict[str, Any]] | None = None,
) -> dict[str, Any]:
    STATE.log(
        "Requesting LLM completion "
        f"(messages={len(messages)}, include_tools={'yes' if include_tools else 'no'}, max_tokens={max_tokens})."
    )
    response = requests.post(
        f"{gateway['base_url']}{gateway['llm_completion_path']}",
        headers={"X-Sandbox-Run-Token": gateway["token"]},
        json={
            "messages": messages,
            "system_prompt": system_prompt,
            "max_tokens": max_tokens,
            "include_tools": include_tools,
            "extra_tools": extra_tools or [],
        },
        timeout=120,
    )
    if response.status_code >= 400:
        raise RuntimeError(f"Sandbox LLM completion failed with HTTP {response.status_code}: {response.text}")
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
    restrict_hosts = bool(network_policy.get("restrict_hosts", True))
    allowed_hosts = [host for host in network_policy.get("allowed_hosts", []) if isinstance(host, str)]
    allowed_schemes = [scheme.lower() for scheme in network_policy.get("allowed_schemes", ["http", "https"]) if isinstance(scheme, str)]
    STATE.log(
        "Installing network guard with allowed hosts: "
        + (", ".join(allowed_hosts) if allowed_hosts else ("<unrestricted>" if not restrict_hosts else "<none>"))
        + f"; schemes: {', '.join(allowed_schemes) if allowed_schemes else '<none>'}."
    )
    original_request = requests.sessions.Session.request
    original_getaddrinfo = socket.getaddrinfo
    original_create_connection = socket.create_connection

    def guarded_request(self, method, url, *args, **kwargs):  # noqa: ANN001
        parsed = urlparse(url)
        scheme = (parsed.scheme or "").lower()
        if scheme not in allowed_schemes:
            raise RuntimeError(f"Outbound scheme '{scheme or '<empty>'}' is not allowed")
        if restrict_hosts:
            enforce_url(url, allowed_hosts, allowed_schemes)
        return original_request(self, method, url, *args, **kwargs)

    def guarded_getaddrinfo(host, *args, **kwargs):  # noqa: ANN001
        if restrict_hosts and isinstance(host, str) and host != "" and not host_matches(host, allowed_hosts):
            raise RuntimeError(f"DNS resolution for host '{host}' is not allowed")
        return original_getaddrinfo(host, *args, **kwargs)

    def guarded_create_connection(address, *args, **kwargs):  # noqa: ANN001
        host = address[0] if isinstance(address, tuple) and address else None
        if restrict_hosts and isinstance(host, str) and host != "" and not host_matches(host, allowed_hosts):
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
    workspaces = payload.get("workspaces", []) or []
    followup_policy = payload.get("followup_policy", {}) or {}
    lineage = task.get("lineage", {}) or {}
    return f"""{profile.get("system_prompt") or ""}

You are an autonomous sandboxed agent run.

Current task:
- task_id: {task.get("id")}
- run_id: {task.get("run_id")}
- task_name: {task.get("name")}
- user_id: {user.get("id")}

Task payload:
```json
{json.dumps(task.get("input_payload", {}), ensure_ascii=False, indent=2)}
```

Task lineage and handoff context:
```json
{json.dumps(lineage, ensure_ascii=False, indent=2)}
```

Persistent agent memory available to you:
```json
{json.dumps(memory, ensure_ascii=False, indent=2)}
```

Tools available through the host gateway:
```json
{json.dumps(payload.get("tools", []), ensure_ascii=False, indent=2)}
```

Local sandbox tools available to you:
```json
{json.dumps(local_tools(), ensure_ascii=False, indent=2)}
```

Materialized workspaces available locally inside the sandbox:
```json
{json.dumps(workspaces, ensure_ascii=False, indent=2)}
```

Follow-up task policy:
```json
{json.dumps(followup_policy, ensure_ascii=False, indent=2)}
```

Rules:
- Use tools when needed to inspect data and verify facts.
- Prefer `workspace_detect_project` before choosing install/test commands.
- Prefer `workspace_run_tests` over ad-hoc shell commands when your goal is to execute a project's test suite.
- Acquire the source material you need before local execution in `/workspace`.
- Materialized user workspaces are mounted under `/workspace/synced-workspaces/<workspace_id>`.
- If you edit files locally inside a materialized workspace, those changes will be synchronized back after the run for writable workspaces.
- Keep each run focused on one bounded deliverable with minimal context growth.
- If the next step is logically separate, delayed, requires user notification, needs PR creation, or would make this run sprawl, create a small follow-up task instead of overloading the current run.
- When creating a follow-up task, pass a concise `context_summary` so the next run can continue without rereading everything.
- Prefer follow-up tasks that can be completed independently in one run.
- Do not invent facts you did not verify directly from tools, files, or command output.
- Report concrete work performed in `actions`.
- Put useful non-persistent observations in `findings`.
- Put paths to generated logs or outputs in `artifacts`.
- Do not say or imply that memory was updated unless you return non-empty `memory_candidates`.
- Use `plan` to list the small step sequence you actually followed or intentionally deferred.
- If you created or intentionally delegated the next step, return a `handoff` object that explains the reason and target.
- If blocked, say exactly what prevented completion in `blocker`.
- Your final answer must be valid JSON only.
- Return exactly one JSON object with:
  {{
    "output": "human-readable result",
    "summary": "short summary",
    "blocker": null,
    "actions": [],
    "findings": [],
    "artifacts": [],
    "memory_candidates": [],
    "plan": [],
    "handoff": null
  }}
"""


def execute_host_tool(gateway: dict[str, Any], tool_name: str, arguments: dict[str, Any] | None = None) -> Any:
    STATE.log(f"Calling tool '{tool_name}' with arguments {json.dumps(arguments or {}, ensure_ascii=False)}.")
    response = requests.post(
        f"{gateway['base_url']}{gateway['tool_call_path']}",
        headers={"X-Sandbox-Run-Token": gateway["token"]},
        json={"tool_name": tool_name, "arguments": arguments or {}},
        timeout=60,
    )
    if response.status_code >= 400:
        raise RuntimeError(f"Sandbox tool call '{tool_name}' failed with HTTP {response.status_code}: {response.text}")
    data = response.json()
    if not data.get("success"):
        raise RuntimeError(data.get("message", "Sandbox tool call failed"))
    result = data.get("data", {}).get("result")
    success_value = result.get("success") if isinstance(result, dict) and "success" in result else None
    STATE.log(f"Tool '{tool_name}' completed" + (f" with success={success_value}." if success_value is not None else "."))
    evidence: dict[str, Any] = {"tool_name": tool_name, "arguments": arguments or {}}
    if isinstance(result, dict):
        if "success" in result:
            evidence["success"] = result.get("success")
        if isinstance(result.get("workspace_path"), str) and result["workspace_path"].strip() != "":
            STATE.register_workspace_dir(result["workspace_path"].strip())
            evidence["workspace_path"] = result["workspace_path"].strip()
        for key in ["path", "ref", "commit_sha", "branch", "project_root"]:
            value = result.get(key) if isinstance(result, dict) else None
            if isinstance(value, str) and value.strip() != "":
                evidence[key] = value.strip()
    STATE.record_action("tool_call", f"Called host tool: {tool_name}", "completed" if success_value is not False else "failed", details=f"host tool {tool_name} executed", evidence=evidence)
    return result


def build_tool_feedback(tool_name: str | None, tool_result: Any) -> str | None:
    if not isinstance(tool_result, dict):
        return None
    if tool_result.get("success") is not False:
        return None

    error_text = str(tool_result.get("error") or "").strip()
    command = str(tool_result.get("command") or "").strip()
    cwd = str(tool_result.get("cwd") or "").strip()
    stderr = str(tool_result.get("stderr") or "").strip()

    hints: list[str] = []
    if "Requested cwd does not exist" in error_text:
        hints.append("The working directory was wrong. Use a discovered workspace path or run workspace_detect_project first.")
    if "escapes /workspace" in error_text:
        hints.append("The command tried to access a path outside /workspace. Stay within the sandbox workspace.")
    if tool_name == "workspace_run_tests":
        hints.append("A structured test run failed. Inspect the install/test results and decide whether another candidate command or a partial setup is more appropriate.")
    if tool_name == "workspace_detect_project" and error_text:
        hints.append("Project detection failed. Inspect the workspace layout before choosing commands.")
    lowered = f"{error_text}\n{stderr}".lower()
    if "npm ci" in command.lower() and ("package-lock" in lowered or "lockfile" in lowered):
        hints.append("npm ci requires a lockfile. Try npm install if the repository does not ship package-lock.json.")
    if "permission denied" in lowered:
        hints.append("The failure is caused by filesystem permissions, not necessarily by the chosen command.")
    if "not found" in lowered:
        hints.append("A required binary or file was missing. Inspect stderr and choose a command that matches the workspace contents.")

    feedback_parts = [f"Tool {tool_name or 'unknown'} failed."]
    if command:
        feedback_parts.append(f"Command: {command}.")
    if cwd:
        feedback_parts.append(f"cwd: {cwd}.")
    if error_text:
        feedback_parts.append(f"Error: {error_text}.")
    if hints:
        feedback_parts.append("Next-step guidance: " + " ".join(hints))
    return " ".join(feedback_parts)


def register_materialized_workspaces(payload: dict[str, Any]) -> None:
    workspaces = payload.get("workspaces", []) or []
    for workspace in workspaces:
        if not isinstance(workspace, dict):
            continue
        sandbox_path = workspace.get("sandbox_path")
        if isinstance(sandbox_path, str) and sandbox_path.strip() != "":
            STATE.register_workspace_dir(sandbox_path.strip())


def attempt_finalization(gateway: dict[str, Any], task: dict[str, Any], last_assistant_content: str | None = None) -> dict[str, Any]:
    finalization_prompt = """You are the finalizer for a sandbox agent run.

Return JSON only with exactly these fields:
{
  "output": "human-readable result",
  "summary": "short summary",
  "blocker": null,
  "actions": [],
  "findings": [],
  "artifacts": [],
  "memory_candidates": [],
  "plan": [],
  "handoff": null
}

Rules:
- Do not call tools.
- Use the provided execution state as the source of truth.
- Preserve verified facts from actions, findings, artifacts, and the last assistant response.
- If the task is complete, produce a valid final result even if the main loop ran out of iterations.
- Only set blocker when the execution state shows a real unresolved blocker.
"""
    finalization_payload = {
        "task_id": task.get("id"),
        "run_id": task.get("run_id"),
        "task_name": task.get("name"),
        "actions": STATE.execution_actions.copy(),
        "findings": STATE.auto_findings.copy(),
        "artifacts": STATE.result_artifacts.copy(),
        "last_assistant_response": last_assistant_content or "",
    }
    finalization_message = call_llm(
        gateway,
        [{"role": "user", "content": "Finalize this sandbox run into the required JSON result:\n\n" + json.dumps(finalization_payload, ensure_ascii=False, indent=2)}],
        finalization_prompt,
        max_tokens=1800,
        include_tools=False,
        extra_tools=[],
    )
    if isinstance(finalization_message.get("tool_calls"), list) and finalization_message.get("tool_calls"):
        raise RuntimeError("Finalization pass attempted tool calls instead of returning JSON")
    finalization_content = finalization_message.get("content")
    if not isinstance(finalization_content, str):
        raise RuntimeError("Finalization pass did not return textual JSON content")
    return normalize_final_content(STATE, finalization_content)


def run_agent_mode(payload: dict[str, Any], args: argparse.Namespace) -> dict[str, Any]:
    gateway = payload.get("gateway", {})
    task = payload.get("task", {})
    STATE.log(f"Starting task {task.get('id')} run {task.get('run_id')} for payload {json.dumps(task.get('input_payload', {}), ensure_ascii=False)}.")
    system_prompt = build_agent_system_prompt(payload)
    messages: list[dict[str, Any]] = [{"role": "user", "content": task.get("prompt") or "Execute the task."}]
    max_iterations = int(task.get("max_iterations") or 8)
    tool_names = [
        function.get("name")
        for tool in payload.get("tools", [])
        if isinstance(tool, dict)
        for function in [tool.get("function", {}) or {}]
        if isinstance(function.get("name"), str)
    ]
    tool_names.extend(list(local_tool_names()))
    prompt_text = str(task.get("prompt") or "")
    requires_tests = "test" in prompt_text.lower()
    last_assistant_content: str | None = None
    last_normalization_error: str | None = None
    if requires_tests and not any(name in ["workspace_run_command", "workspace_run_tests"] or "test" in name for name in tool_names):
        STATE.record_action("run_tests", "Prepare test execution", "blocked", details="No available tool appears able to execute tests.")
        STATE.add_finding("Task requested tests, but no obvious test-execution capability is available.")

    for _ in range(max_iterations):
        assistant_message = call_llm(gateway, messages, system_prompt, extra_tools=local_tools())
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
                except Exception:
                    arguments = {}
                try:
                    tool_result = execute_local_tool(STATE, tool_name, arguments) if isinstance(tool_name, str) and tool_name in local_tool_names() else execute_host_tool(gateway, tool_name, arguments)
                except Exception as exc:
                    STATE.log(f"Tool '{tool_name}' failed with error: {exc}")
                    STATE.record_action(
                        "tool_error",
                        f"Tool failed: {tool_name or 'unknown'}",
                        "failed",
                        details=str(exc),
                        evidence={"tool_name": tool_name, "arguments": arguments},
                    )
                    STATE.add_finding(f"Tool {tool_name or 'unknown'} failed: {exc}")
                    tool_result = {"success": False, "tool_name": tool_name, "error": str(exc)}
                messages.append({"role": "tool", "tool_call_id": tool_call_item.get("id") or str(uuid.uuid4()), "content": json.dumps(tool_result, ensure_ascii=False)})
                feedback = build_tool_feedback(tool_name if isinstance(tool_name, str) else None, tool_result)
                if feedback:
                    messages.append({"role": "user", "content": feedback})
            continue

        content = assistant_message.get("content")
        if isinstance(content, str):
            last_assistant_content = content
            try:
                normalized = normalize_final_content(STATE, content)
                last_normalization_error = None
            except RuntimeError as exc:
                last_normalization_error = str(exc)
                try:
                    normalized = repair_final_content(STATE, call_llm, gateway, content)
                    last_normalization_error = None
                except RuntimeError as repair_exc:
                    last_normalization_error = str(repair_exc)
                    STATE.log(f"Final response normalization failed; continuing agent loop: {repair_exc}")
                    STATE.record_action(
                        "normalize_response",
                        "Normalize final agent response",
                        "failed",
                        details=str(repair_exc),
                        evidence={"raw_content": content[:2000]},
                    )
                    STATE.add_finding(f"Agent returned a non-normalizable final response: {repair_exc}")
                    continue
            if len(normalized["memory_candidates"]) == 0 and (normalized["output"].strip() != "" or normalized["summary"].strip() != ""):
                STATE.log("No memory candidates returned; running extraction pass.")
                extracted = extract_memory_candidates(
                    call_llm,
                    gateway,
                    normalized["output"],
                    normalized["summary"],
                    normalized["actions"],
                    normalized["findings"],
                    normalized["artifacts"],
                )
                if extracted:
                    normalized["memory_candidates"] = extracted
                    normalized["blocker"] = None
                    STATE.log(f"Extraction pass recovered {len(extracted)} memory candidate(s).")
            if requires_tests and not any(action.get("type") == "run_tests" and action.get("status") in ["completed", "failed"] for action in STATE.execution_actions):
                STATE.add_finding("No test command was actually executed during this run.")
            return normalized

    try:
        finalized = attempt_finalization(gateway, task, last_assistant_content)
        if len(finalized["memory_candidates"]) == 0 and (finalized["output"].strip() != "" or finalized["summary"].strip() != ""):
            STATE.log("No memory candidates returned after forced finalization; running extraction pass.")
            extracted = extract_memory_candidates(
                call_llm,
                gateway,
                finalized["output"],
                finalized["summary"],
                finalized["actions"],
                finalized["findings"],
                finalized["artifacts"],
            )
            if extracted:
                finalized["memory_candidates"] = extracted
                finalized["blocker"] = None
        if requires_tests and not any(action.get("type") == "run_tests" and action.get("status") in ["completed", "failed"] for action in STATE.execution_actions):
            STATE.add_finding("No test command was actually executed during this run.")
        return finalized
    except RuntimeError as exc:
        last_normalization_error = str(exc)
        STATE.log(f"Forced finalization after max iterations failed: {exc}")
        STATE.record_action(
            "finalize_response",
            "Finalize agent response after max iterations",
            "failed",
            details=str(exc),
            evidence={"last_assistant_response": (last_assistant_content or "")[:2000]},
        )
        STATE.add_finding(f"Forced finalization failed after max iterations: {exc}")

    blocker_parts = ["Agent loop reached max iterations without a valid final answer."]
    if last_normalization_error:
        blocker_parts.append(last_normalization_error)
    if requires_tests and not any(action.get("type") == "run_tests" and action.get("status") in ["completed", "failed"] for action in STATE.execution_actions):
        STATE.add_finding("No test command was actually executed during this run.")
    fallback_output = None
    if isinstance(last_assistant_content, str) and last_assistant_content.strip() != "":
        fallback_output = "Last agent response could not be finalized into the required result format."
    return build_fallback_result(
        STATE,
        " ".join(blocker_parts),
        output=fallback_output,
        summary="Run stopped at max iterations.",
    )


def main() -> int:
    args = parse_args()
    payload = json.loads(pathlib.Path(args.input).read_text())
    STATE.set_artifacts_root(args.artifacts_dir)
    register_materialized_workspaces(payload)
    STATE.log(f"Loaded task payload from {args.input}.")
    install_network_guard(payload.get("network_policy", {}))
    try:
        final_result = run_agent_mode(payload, args)
        result_payload = {
            "success": True,
            "output": final_result["output"],
            "summary": final_result["summary"],
            "blocker": final_result["blocker"],
            "actions": final_result["actions"],
            "findings": final_result["findings"],
            "artifacts": final_result["artifacts"],
            "memory_candidates": final_result["memory_candidates"],
            "plan": final_result.get("plan", []),
            "handoff": final_result.get("handoff"),
        }
        STATE.log(
            "Run completed successfully with "
            f"{len(final_result['memory_candidates'])} memory candidate(s)"
            + (f"; blocker: {final_result['blocker']}." if final_result["blocker"] else ".")
        )
    except Exception as exc:
        STATE.log(f"Run failed: {exc}")
        write_result(args.output, {"success": False, "error": str(exc), "actions": STATE.execution_actions.copy(), "findings": STATE.auto_findings.copy(), "artifacts": STATE.result_artifacts.copy()})
        return 1

    write_result(args.output, result_payload)
    return 0


if __name__ == "__main__":
    sys.exit(main())
