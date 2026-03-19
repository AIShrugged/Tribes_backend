from __future__ import annotations

import json
from typing import Any, Callable

from runtime_state import SandboxRuntimeState


def coerce_text(value: Any) -> str | None:
    if isinstance(value, str):
        cleaned = value.strip()
        return cleaned if cleaned != "" else None
    return None


def compact_json(value: Any) -> str | None:
    if value in [None, "", [], {}]:
        return None
    try:
        return json.dumps(value, ensure_ascii=False)
    except Exception:
        return None


def normalize_plan_items(items: Any) -> list[str]:
    if not isinstance(items, list):
        return []
    normalized: list[str] = []
    for item in items:
        if isinstance(item, str) and item.strip() != "" and item.strip() not in normalized:
            normalized.append(item.strip())
    return normalized


def normalize_handoff(item: Any) -> dict[str, Any] | None:
    if not isinstance(item, dict):
        return None
    normalized: dict[str, Any] = {}
    for key in ["reason", "target", "context_summary", "status"]:
        value = item.get(key)
        if isinstance(value, str) and value.strip() != "":
            normalized[key] = value.strip()
    followup_task_id = item.get("followup_task_id")
    if isinstance(followup_task_id, int):
        normalized["followup_task_id"] = followup_task_id
    elif isinstance(followup_task_id, str) and followup_task_id.strip().isdigit():
        normalized["followup_task_id"] = int(followup_task_id.strip())
    return normalized or None


def normalize_action_item(state: SandboxRuntimeState, item: Any) -> dict[str, Any] | None:
    if isinstance(item, str):
        cleaned = item.strip()
        if cleaned == "":
            return None
        return {"id": state.next_action_id(), "type": "note", "title": cleaned, "status": "completed"}
    if not isinstance(item, dict):
        return None
    title = item.get("title")
    if not isinstance(title, str) or title.strip() == "":
        return None
    normalized = {
        "id": str(item.get("id") or state.next_action_id()),
        "type": str(item.get("type") or "action"),
        "title": title.strip(),
        "status": str(item.get("status") or "completed"),
    }
    if isinstance(item.get("details"), str) and item["details"].strip() != "":
        normalized["details"] = item["details"].strip()
    if isinstance(item.get("evidence"), dict) and item["evidence"]:
        normalized["evidence"] = item["evidence"]
    return normalized


def normalize_artifact_item(item: Any) -> dict[str, Any] | None:
    if not isinstance(item, dict):
        return None
    if not all(isinstance(item.get(key), str) and item[key].strip() != "" for key in ["kind", "label", "path"]):
        return None
    normalized = {"kind": item["kind"].strip(), "label": item["label"].strip(), "path": item["path"].strip()}
    if isinstance(item.get("description"), str) and item["description"].strip() != "":
        normalized["description"] = item["description"].strip()
    return normalized


def normalize_findings(items: Any) -> list[str]:
    if not isinstance(items, list):
        return []
    findings: list[str] = []
    for item in items:
        if isinstance(item, str) and item.strip() != "" and item.strip() not in findings:
            findings.append(item.strip())
    return findings


def append_memory_candidate(
    collection: list[dict[str, Any]],
    *,
    kind: str,
    content: str | None,
    priority: int = 50,
    scope_type: str | None = None,
    scope_key: str | None = None,
) -> None:
    if not isinstance(content, str) or content.strip() == "":
        return
    normalized = {
        "kind": kind.strip() or "fact",
        "content": content.strip(),
        "priority": max(0, min(100, int(priority))),
    }
    if isinstance(scope_type, str) and scope_type.strip() != "":
        normalized["scope_type"] = scope_type.strip()
    if isinstance(scope_key, str) and scope_key.strip() != "":
        normalized["scope_key"] = scope_key.strip()
    if normalized not in collection:
        collection.append(normalized)


def infer_repository_scope(actions: list[dict[str, Any]]) -> str | None:
    for action in actions:
        if not isinstance(action, dict):
            continue
        evidence = action.get("evidence") if isinstance(action.get("evidence"), dict) else {}
        arguments = evidence.get("arguments") if isinstance(evidence.get("arguments"), dict) else {}
        owner = arguments.get("owner")
        repo = arguments.get("repo")
        if isinstance(owner, str) and owner.strip() != "" and isinstance(repo, str) and repo.strip() != "":
            return f"github:{owner.strip().lower()}/{repo.strip().lower()}"
    return None


def summarize_execution_report(actions: list[dict[str, Any]], findings: list[str], blocker: str | None, base_output: str, base_summary: str) -> tuple[str, str]:
    action_count = len(actions)
    completed = sum(1 for action in actions if action.get("status") == "completed")
    failed = sum(1 for action in actions if action.get("status") == "failed")
    blocked = sum(1 for action in actions if action.get("status") == "blocked")
    test_actions = [action for action in actions if action.get("type") == "run_tests"]
    bits = [f"Completed {action_count} action(s)"]
    if completed or failed or blocked:
        bits.append(f"statuses: completed={completed}, failed={failed}, blocked={blocked}")
    if test_actions:
        latest = test_actions[-1]
        details = latest.get("details")
        bits.append(f"latest test run: {details.strip()}" if isinstance(details, str) and details.strip() != "" else f"latest test status: {latest.get('status')}")
    output_parts = [base_output.strip(), ". ".join(bits) + "."]
    if findings:
        output_parts.append("Findings: " + "; ".join(findings[:5]) + ".")
    if blocker:
        output_parts.append("Blocker: " + blocker)
    summary_parts = [base_summary.strip()]
    if test_actions:
        detail = test_actions[-1].get("details")
        if isinstance(detail, str) and detail.strip() != "":
            summary_parts.append(f"Tests: {detail.strip()}.")
    elif action_count > 0:
        summary_parts.append(f"Actions recorded: {action_count}.")
    if blocker:
        summary_parts.append(f"Blocker: {blocker}")
    return "\n".join(part for part in output_parts if part and part.strip() != ""), " ".join(part for part in summary_parts if part and part.strip() != "")


def build_fallback_result(
    state: SandboxRuntimeState,
    blocker: str,
    output: str | None = None,
    summary: str | None = None,
    memory_candidates: list[dict[str, Any]] | None = None,
) -> dict[str, Any]:
    merged_actions = state.execution_actions.copy()
    merged_findings = state.auto_findings.copy()
    merged_artifacts = state.result_artifacts.copy()
    base_output = (output or "").strip() or "Run ended without a valid final JSON response from the agent."
    base_summary = (summary or "").strip() or base_output
    final_output, final_summary = summarize_execution_report(
        merged_actions,
        merged_findings,
        blocker.strip(),
        base_output,
        base_summary,
    )
    return {
        "output": final_output,
        "summary": final_summary,
        "blocker": blocker.strip(),
        "actions": merged_actions,
        "findings": merged_findings,
        "artifacts": merged_artifacts,
        "memory_candidates": memory_candidates or [],
        "plan": [],
        "handoff": None,
    }


def infer_memory_candidates(
    actions: list[dict[str, Any]],
    findings: list[str],
    artifacts: list[dict[str, Any]] | None = None,
) -> list[dict[str, Any]]:
    inferred: list[dict[str, Any]] = []
    repository_scope = infer_repository_scope(actions)

    for action in actions:
        if not isinstance(action, dict):
            continue
        action_type = str(action.get("type") or "").strip()
        evidence = action.get("evidence") if isinstance(action.get("evidence"), dict) else {}
        details = str(action.get("details") or "").strip()

        if action_type == "detect_project":
            project_root = evidence.get("project_root")
            project_types = evidence.get("project_types") if isinstance(evidence.get("project_types"), list) else []
            frameworks = evidence.get("frameworks") if isinstance(evidence.get("frameworks"), list) else []
            manifests = [key for key, present in (evidence.get("manifests") or {}).items() if present is True] if isinstance(evidence.get("manifests"), dict) else []
            if isinstance(project_root, str) and project_root.strip() != "":
                append_memory_candidate(
                    inferred,
                    kind="repo_structure_fact",
                    content=f"Detected project root {project_root.strip()} in the sandbox workspace.",
                    priority=72,
                    scope_type="repository" if repository_scope else None,
                    scope_key=repository_scope,
                )
            if project_types:
                append_memory_candidate(
                    inferred,
                    kind="tooling_fact",
                    content=f"Repository project types detected: {', '.join(str(item) for item in project_types if str(item).strip() != '')}.",
                    priority=76,
                    scope_type="repository" if repository_scope else None,
                    scope_key=repository_scope,
                )
            if frameworks:
                append_memory_candidate(
                    inferred,
                    kind="tooling_fact",
                    content=f"Repository frameworks detected: {', '.join(str(item) for item in frameworks if str(item).strip() != '')}.",
                    priority=74,
                    scope_type="repository" if repository_scope else None,
                    scope_key=repository_scope,
                )
            if manifests:
                append_memory_candidate(
                    inferred,
                    kind="repo_structure_fact",
                    content=f"Detected dependency manifests: {', '.join(manifests)}.",
                    priority=65,
                    scope_type="repository" if repository_scope else None,
                    scope_key=repository_scope,
                )

        if action_type == "tool_call":
            tool_name = str(evidence.get("tool_name") or "").strip()
            if tool_name == "github_get_branch":
                owner = evidence.get("arguments", {}).get("owner") if isinstance(evidence.get("arguments"), dict) else None
                repo = evidence.get("arguments", {}).get("repo") if isinstance(evidence.get("arguments"), dict) else None
                branch = evidence.get("branch")
                if isinstance(owner, str) and isinstance(repo, str) and isinstance(branch, str) and branch.strip() != "":
                    append_memory_candidate(
                        inferred,
                        kind="repo_revision_fact",
                        content=f"Resolved repository {owner}/{repo} default branch/ref as {branch.strip()}.",
                        priority=83,
                        scope_type="repository",
                        scope_key=f"github:{owner.strip().lower()}/{repo.strip().lower()}",
                    )
            if tool_name == "github_download_archive":
                arguments = evidence.get("arguments") if isinstance(evidence.get("arguments"), dict) else {}
                owner = arguments.get("owner")
                repo = arguments.get("repo")
                ref = arguments.get("ref")
                destination = arguments.get("destination")
                if isinstance(owner, str) and isinstance(repo, str) and isinstance(ref, str):
                    dest_suffix = f" into {destination.strip()}" if isinstance(destination, str) and destination.strip() != "" else ""
                    append_memory_candidate(
                        inferred,
                        kind="workflow_fact",
                        content=f"Downloaded repository archive for {owner}/{repo} at ref {ref.strip()}{dest_suffix}.",
                        priority=78,
                        scope_type="repository",
                        scope_key=f"github:{owner.strip().lower()}/{repo.strip().lower()}",
                    )

        if action_type in ["install_dependencies", "run_tests", "run_command"]:
            command = evidence.get("command")
            exit_code = evidence.get("exit_code")
            if isinstance(command, str) and command.strip() != "":
                if action_type == "install_dependencies":
                    if exit_code == 0:
                        append_memory_candidate(
                            inferred,
                            kind="workflow_fact",
                            content=f"Dependency installation command succeeded: {command.strip()}.",
                            priority=62,
                            scope_type="repository" if repository_scope else None,
                            scope_key=repository_scope,
                        )
                    elif exit_code is not None:
                        append_memory_candidate(
                            inferred,
                            kind="tooling_fact",
                            content=f"Dependency installation command failed with exit code {exit_code}: {command.strip()}.",
                            priority=69,
                            scope_type="repository" if repository_scope else None,
                            scope_key=repository_scope,
                        )
                if action_type == "run_tests":
                    if exit_code == 0:
                        append_memory_candidate(
                            inferred,
                            kind="test_fact",
                            content=f"Test command succeeded: {command.strip()} ({details or 'exit_code=0'}).",
                            priority=88,
                            scope_type="repository" if repository_scope else None,
                            scope_key=repository_scope,
                        )
                    elif exit_code is not None:
                        append_memory_candidate(
                            inferred,
                            kind="test_fact",
                            content=f"Test command failed: {command.strip()} ({details or f'exit_code={exit_code}'}).",
                            priority=90,
                            scope_type="repository" if repository_scope else None,
                            scope_key=repository_scope,
                        )

    for finding in findings:
        lowered = finding.lower()
        if "lockfile" in lowered or "package-lock" in lowered:
            append_memory_candidate(
                inferred,
                kind="tooling_fact",
                content=finding,
                priority=68,
                scope_type="repository" if repository_scope else None,
                scope_key=repository_scope,
            )
        if "structured test workflow" in lowered:
            append_memory_candidate(
                inferred,
                kind="test_fact",
                content=finding,
                priority=70,
                scope_type="repository" if repository_scope else None,
                scope_key=repository_scope,
            )

    return inferred


def synthesize_structured_output(parsed: dict[str, Any]) -> str | None:
    parts: list[str] = []

    summary_block = parsed.get("summary")
    if isinstance(summary_block, dict):
        repository_type = coerce_text(summary_block.get("repositoryType"))
        package_manager = coerce_text(summary_block.get("packageManager"))
        dependency_status = coerce_text(summary_block.get("dependencyInstallationStatus"))
        failure_cause = coerce_text(summary_block.get("failureCause"))
        testing_status = coerce_text(summary_block.get("testingStatus"))
        primary_issue = coerce_text(summary_block.get("primaryIssue"))

        repo_bits = [bit for bit in [repository_type, package_manager] if bit]
        if repo_bits:
            parts.append("Repository context: " + ", ".join(repo_bits) + ".")
        if dependency_status:
            sentence = f"Dependency installation status: {dependency_status}"
            if failure_cause:
                sentence += f" ({failure_cause})"
            parts.append(sentence + ".")
        if testing_status:
            parts.append(f"Testing status: {testing_status}.")
        if primary_issue:
            parts.append(f"Primary issue: {primary_issue}.")

    test_run = parsed.get("test_run")
    if isinstance(test_run, dict):
        dependency_installation = test_run.get("dependency_installation")
        if isinstance(dependency_installation, dict):
            method = coerce_text(dependency_installation.get("method"))
            result = coerce_text(dependency_installation.get("result"))
            if method or result:
                parts.append(
                    "Dependency installation "
                    + ("via " + method if method else "")
                    + (" was " + result if result else "")
                    + "."
                )

        test_execution = test_run.get("test_execution")
        if isinstance(test_execution, dict):
            result = coerce_text(test_execution.get("result"))
            reason = coerce_text(test_execution.get("reason"))
            details = test_execution.get("details") if isinstance(test_execution.get("details"), dict) else {}
            error = coerce_text(details.get("error")) if isinstance(details, dict) else None

            sentence = "Test execution"
            if result:
                sentence += f" {result}"
            if reason:
                sentence += f" because of {reason}"
            if error:
                sentence += f" ({error})"
            if sentence != "Test execution":
                parts.append(sentence + ".")

    repository_architecture = parsed.get("repository_architecture")
    if isinstance(repository_architecture, dict):
        backend = coerce_text(repository_architecture.get("backend"))
        additional = coerce_text(repository_architecture.get("additional_components"))
        database_requirement = coerce_text(repository_architecture.get("database_requirement"))
        env_status = coerce_text(repository_architecture.get("current_environment_status"))

        architecture_bits = [bit for bit in [backend, additional] if bit]
        if architecture_bits:
            parts.append("Repository architecture: " + ", ".join(architecture_bits) + ".")
        if database_requirement:
            parts.append(f"Database requirement: {database_requirement}.")
        if env_status:
            parts.append(f"Environment status: {env_status}.")

    recommendations = parsed.get("recommendations")
    if isinstance(recommendations, list):
        normalized = [item.strip() for item in recommendations if isinstance(item, str) and item.strip() != ""]
        if normalized:
            parts.append("Recommendations: " + "; ".join(normalized[:3]) + ".")

    return "\n".join(parts) if parts else None


def synthesize_structured_summary(parsed: dict[str, Any]) -> str | None:
    summary_block = parsed.get("summary")
    if isinstance(summary_block, dict):
        dependency_status = coerce_text(summary_block.get("dependencyInstallationStatus"))
        failure_cause = coerce_text(summary_block.get("failureCause"))
        testing_status = coerce_text(summary_block.get("testingStatus"))
        primary_issue = coerce_text(summary_block.get("primaryIssue"))

        if dependency_status and failure_cause:
            return f"Dependency installation {dependency_status}; {failure_cause}."
        if dependency_status and testing_status:
            return f"Dependency installation {dependency_status}; testing status: {testing_status}."
        if primary_issue:
            return primary_issue

    test_run = parsed.get("test_run")
    if isinstance(test_run, dict):
        test_execution = test_run.get("test_execution")
        if isinstance(test_execution, dict):
            result = coerce_text(test_execution.get("result"))
            reason = coerce_text(test_execution.get("reason"))
            if result and reason:
                return f"Tests {result} because of {reason}."
            if result:
                return f"Tests {result}."

    repository_architecture = parsed.get("repository_architecture")
    if isinstance(repository_architecture, dict):
        env_status = coerce_text(repository_architecture.get("current_environment_status"))
        if env_status:
            return env_status

    return None


def infer_structured_findings(parsed: dict[str, Any]) -> list[str]:
    findings: list[str] = []

    summary_block = parsed.get("summary")
    if isinstance(summary_block, dict):
        for key in [
            "repositoryType",
            "packageManager",
            "dependencyInstallationStatus",
            "failureCause",
            "testingStatus",
            "primaryIssue",
        ]:
            value = coerce_text(summary_block.get(key))
            if value:
                findings.append(f"{key}: {value}.")

    test_run = parsed.get("test_run")
    if isinstance(test_run, dict):
        commands = test_run.get("commands")
        if isinstance(commands, list):
            normalized = [item.strip() for item in commands if isinstance(item, str) and item.strip() != ""]
            if normalized:
                findings.append("Test commands: " + ", ".join(normalized) + ".")
        elif isinstance(commands, str) and commands.strip() != "":
            findings.append("Test commands: " + commands.strip() + ".")

        test_execution = test_run.get("test_execution")
        if isinstance(test_execution, dict):
            details = test_execution.get("details") if isinstance(test_execution.get("details"), dict) else {}
            if isinstance(details, dict):
                error = coerce_text(details.get("error"))
                database_type = coerce_text(details.get("database_type"))
                database_name = coerce_text(details.get("database_name"))
                host = coerce_text(details.get("host"))
                port = details.get("port")
                if error:
                    findings.append("Test execution error: " + error + ".")
                location_bits = [bit for bit in [database_type, database_name, host] if bit]
                if location_bits or port is not None:
                    suffix = f":{port}" if port is not None else ""
                    findings.append("Database context: " + ", ".join(location_bits) + suffix + ".")

    repository_architecture = parsed.get("repository_architecture")
    if isinstance(repository_architecture, dict):
        test_commands = repository_architecture.get("test_commands")
        if isinstance(test_commands, list):
            normalized = [item.strip() for item in test_commands if isinstance(item, str) and item.strip() != ""]
            if normalized:
                findings.append("Repository test commands: " + ", ".join(normalized) + ".")

    recommendations = parsed.get("recommendations")
    if isinstance(recommendations, list):
        for item in recommendations:
            if isinstance(item, str) and item.strip() != "":
                findings.append(item.strip())

    recommended_next_steps = parsed.get("recommendedNextSteps")
    if isinstance(recommended_next_steps, list):
        for item in recommended_next_steps:
            if isinstance(item, str) and item.strip() != "":
                findings.append(item.strip())

    additional_notes = coerce_text(parsed.get("additionalNotes"))
    if additional_notes:
        findings.append(additional_notes)

    deduped: list[str] = []
    for item in findings:
        if item not in deduped:
            deduped.append(item)
    return deduped


def infer_structured_blocker(parsed: dict[str, Any]) -> str | None:
    summary_block = parsed.get("summary")
    if isinstance(summary_block, dict):
        primary_issue = coerce_text(summary_block.get("primaryIssue"))
        failure_cause = coerce_text(summary_block.get("failureCause"))
        if primary_issue and failure_cause:
            return f"{primary_issue}: {failure_cause}"
        if primary_issue:
            return primary_issue
        if failure_cause:
            return failure_cause

    test_run = parsed.get("test_run")
    if isinstance(test_run, dict):
        test_execution = test_run.get("test_execution")
        if isinstance(test_execution, dict):
            reason = coerce_text(test_execution.get("reason"))
            details = test_execution.get("details") if isinstance(test_execution.get("details"), dict) else {}
            error = coerce_text(details.get("error")) if isinstance(details, dict) else None
            if reason and error:
                return f"{reason}: {error}"
            if reason:
                return reason
            if error:
                return error
    return None


def normalize_final_content(state: SandboxRuntimeState, content: str) -> dict[str, Any]:
    content = (content or "").strip()
    if content == "":
        raise RuntimeError("Agent returned an empty final response instead of the required JSON object")
    if content.startswith("```"):
        lines = content.splitlines()
        if len(lines) >= 3 and lines[0].startswith("```") and lines[-1].strip() == "```":
            content = "\n".join(lines[1:-1]).strip()
    try:
        parsed = json.loads(content)
    except Exception as exc:
        raise RuntimeError(f"Agent final response must be valid JSON with output, summary, memory_candidates. Raw response: {content}") from exc
    if not isinstance(parsed, dict):
        raise RuntimeError("Agent final response JSON must be an object")

    output = parsed.get("output")
    summary = parsed.get("summary")
    blocker = parsed.get("blocker")
    actions = parsed.get("actions")
    findings = parsed.get("findings")
    artifacts = parsed.get("artifacts")
    memory_candidates = parsed.get("memory_candidates")
    plan = parsed.get("plan")
    handoff = parsed.get("handoff")

    if not isinstance(output, str):
        output = next((
            candidate
            for candidate in [
                coerce_text(parsed.get("final_answer")),
                coerce_text(parsed.get("answer")),
                coerce_text(parsed.get("result")),
                coerce_text(parsed.get("message")),
                coerce_text(parsed.get("current_status")),
                coerce_text(parsed.get("details")),
                coerce_text(summary),
                coerce_text(parsed.get("next_steps")),
                coerce_text(parsed.get("error", {}).get("message") if isinstance(parsed.get("error"), dict) else None),
                synthesize_structured_output(parsed),
            ]
            if candidate is not None
        ), None)
    if not isinstance(summary, str):
        summary = next((
            candidate
            for candidate in [
                coerce_text(parsed.get("summary_text")),
                coerce_text(parsed.get("short_summary")),
                coerce_text(parsed.get("current_status")),
                coerce_text(parsed.get("error", {}).get("message") if isinstance(parsed.get("error"), dict) else None),
                synthesize_structured_summary(parsed),
                coerce_text(output),
            ]
            if candidate is not None
        ), None)
    if not isinstance(memory_candidates, list):
        memory_candidates = next((candidate for candidate in [parsed.get("memories"), parsed.get("memory_facts"), parsed.get("facts")] if isinstance(candidate, list)), None)
    if memory_candidates is None:
        memory_candidates = []

    if not isinstance(actions, list):
        actions = []
    merged_actions = [item for item in (normalize_action_item(state, action) for action in actions) if item is not None]

    merged_findings = normalize_findings(findings)
    for item in infer_structured_findings(parsed):
        if item not in merged_findings:
            merged_findings.append(item)
    for fallback_findings in [parsed.get("setup_instructions"), parsed.get("uses")]:
        if isinstance(fallback_findings, list):
            for item in normalize_findings(fallback_findings):
                if item not in merged_findings:
                    merged_findings.append(item)
    if isinstance(parsed.get("repository"), dict):
        repository_summary = compact_json(parsed["repository"])
        if repository_summary and repository_summary not in merged_findings:
            merged_findings.append(f"Repository context: {repository_summary}")
    if not isinstance(artifacts, list):
        artifacts = []
    merged_artifacts = [item for item in (normalize_artifact_item(artifact) for artifact in artifacts) if item is not None]
    normalized_plan = normalize_plan_items(plan)
    normalized_handoff = normalize_handoff(handoff)

    if blocker is not None and (not isinstance(blocker, str) or blocker.strip() == ""):
        blocker = None
    if isinstance(blocker, str):
        blocker = blocker.strip()
    if blocker is None:
        blocker = next((
            candidate
            for candidate in [
                coerce_text(parsed.get("current_status")) if "unable" in str(parsed.get("current_status", "")).lower() else None,
                coerce_text(parsed.get("error", {}).get("message") if isinstance(parsed.get("error"), dict) else None),
                coerce_text(parsed.get("error", {}).get("details") if isinstance(parsed.get("error"), dict) else None),
                infer_structured_blocker(parsed),
            ]
            if candidate is not None
        ), None)

    normalized_candidates: list[dict[str, Any]] = []
    for item in memory_candidates:
        if isinstance(item, dict):
            candidate_content = item.get("content")
            if isinstance(candidate_content, str) and candidate_content.strip() != "":
                normalized_candidates.append({"kind": str(item.get("kind") or "fact"), "content": candidate_content.strip(), "priority": int(item.get("priority") or 50)})
        elif isinstance(item, str) and item.strip() != "":
            normalized_candidates.append({"kind": "fact", "content": item.strip(), "priority": 50})
    memory_candidates = normalized_candidates

    if not isinstance(output, str) or output.strip() == "":
        synthesized_bits = [
            coerce_text(parsed.get("current_status")),
            coerce_text(parsed.get("error", {}).get("message") if isinstance(parsed.get("error"), dict) else None),
            coerce_text(parsed.get("next_steps")),
            coerce_text(parsed.get("details")),
        ]
        output = "\n".join(bit for bit in synthesized_bits if bit)
    if not isinstance(output, str) or output.strip() == "":
        useful_payload = {
            key: value
            for key, value in parsed.items()
            if key not in ["actions", "artifacts", "memory_candidates"] and value not in [None, "", [], {}]
        }
        payload_text = compact_json(useful_payload)
        if payload_text:
            output = f"Agent returned a non-standard final response: {payload_text}"
    if not isinstance(output, str) or output.strip() == "":
        raise RuntimeError(f"Agent final response JSON must contain a non-empty output string. Raw response: {content}")
    if not isinstance(summary, str) or summary.strip() == "":
        summary = output
    if len(memory_candidates) == 0 and ("memory updated" in output.lower() or "memory updated" in summary.lower()):
        raise RuntimeError("Agent claimed that memory was updated but returned empty memory_candidates")
    if len(memory_candidates) == 0 and blocker is None and len(merged_findings) == 0 and len(merged_actions) == 0:
        blocker = "Run completed without validated findings or memory candidates."

    for auto_action in state.execution_actions:
        if auto_action not in merged_actions:
            merged_actions.append(auto_action)
    for item in state.auto_findings:
        if item not in merged_findings:
            merged_findings.append(item)
    for artifact in state.result_artifacts:
        if artifact not in merged_artifacts:
            merged_artifacts.append(artifact)

    output, summary = summarize_execution_report(merged_actions, merged_findings, blocker, output, summary)
    return {
        "output": output,
        "summary": summary,
        "blocker": blocker,
        "actions": merged_actions,
        "findings": merged_findings,
        "artifacts": merged_artifacts,
        "memory_candidates": memory_candidates,
        "plan": normalized_plan,
        "handoff": normalized_handoff,
    }


def repair_final_content(state: SandboxRuntimeState, call_llm: Callable[..., dict[str, Any]], gateway: dict[str, Any], raw_content: str) -> dict[str, Any]:
    repair_prompt = """You are a response normalizer.

Your only job is to convert the provided agent output into a valid JSON object with exactly these fields:
- output: string
- summary: string
- blocker: string or null
- actions: array
- findings: array
- artifacts: array
- memory_candidates: array
- plan: array
- handoff: object or null

Rules:
- Return JSON only. No markdown. No explanations.
- Preserve factual content from the original response.
- Do not invent new facts.
- Always return all nine top-level fields, even if some are empty.
"""
    repair_messages = [{"role": "user", "content": f"Normalize this agent output into the required JSON object:\n\n{raw_content}"}]
    prompts = [repair_prompt, repair_prompt + '\nReturn exactly {"output":"...","summary":"...","blocker":null,"actions":[],"findings":[],"artifacts":[],"memory_candidates":[],"plan":[],"handoff":null}']
    last_error: Exception | None = None
    for prompt in prompts:
        repaired_message = call_llm(gateway, repair_messages, prompt, max_tokens=1600, include_tools=False, extra_tools=[])
        if isinstance(repaired_message.get("tool_calls"), list) and repaired_message.get("tool_calls"):
            last_error = RuntimeError("Repair pass attempted tool calls instead of returning final JSON")
            continue
        repaired_content = repaired_message.get("content")
        if not isinstance(repaired_content, str):
            last_error = RuntimeError("Repair pass did not return textual JSON content")
            continue
        try:
            return normalize_final_content(state, repaired_content)
        except RuntimeError as exc:
            last_error = exc
    if last_error is not None:
        raise last_error
    raise RuntimeError("Repair pass failed to produce a valid JSON response")


def extract_memory_candidates(
    call_llm: Callable[..., dict[str, Any]],
    gateway: dict[str, Any],
    output: str,
    summary: str,
    actions: list[dict[str, Any]] | None = None,
    findings: list[str] | None = None,
    artifacts: list[dict[str, Any]] | None = None,
) -> list[dict[str, Any]]:
    heuristic = infer_memory_candidates(actions or [], findings or [], artifacts or [])
    if heuristic:
        return heuristic

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
- Extract only concrete task-specific facts worth remembering.
- Prefer architecture_fact, tooling_fact, workflow_fact, integration_fact, repo_revision_fact, roadmap_fact when relevant.
- Use the structured actions/findings/artifacts as primary evidence, not just the prose summary.
- Do not invent facts.
- If there are no reliable facts, return an empty array.
"""
    extraction_payload = {
        "output": output,
        "summary": summary,
        "actions": actions or [],
        "findings": findings or [],
        "artifacts": artifacts or [],
    }
    extraction_message = call_llm(
        gateway,
        [{"role": "user", "content": "Extract memory candidates from this structured sandbox result:\n\n" + json.dumps(extraction_payload, ensure_ascii=False, indent=2)}],
        extraction_prompt,
        max_tokens=1400,
        include_tools=False,
        extra_tools=[],
    )
    if isinstance(extraction_message.get("tool_calls"), list) and extraction_message.get("tool_calls"):
        raise RuntimeError("Memory extraction pass attempted tool calls instead of returning JSON")
    extraction_content = extraction_message.get("content")
    if not isinstance(extraction_content, str):
        return []
    try:
        parsed = json.loads(extraction_content)
    except Exception:
        return []
    if not isinstance(parsed, dict) or not isinstance(parsed.get("memory_candidates"), list):
        return []
    normalized: list[dict[str, Any]] = []
    for item in parsed["memory_candidates"]:
        if isinstance(item, dict) and isinstance(item.get("content"), str) and item["content"].strip() != "":
            normalized.append({"kind": str(item.get("kind") or "fact"), "content": item["content"].strip(), "priority": int(item.get("priority") or 50)})
    return normalized
