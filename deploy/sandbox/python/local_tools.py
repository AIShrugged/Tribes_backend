from __future__ import annotations

import json
import os
import pathlib
import re
import subprocess
from typing import Any

from runtime_state import SandboxRuntimeState


MAX_COMMAND_OUTPUT_CHARS = 20000


def local_tools() -> list[dict[str, Any]]:
    return [
        {
            "type": "function",
            "function": {
                "name": "read_file_lines",
                "description": (
                    "Read specific lines from a file inside the sandbox workspace. "
                    "Use this instead of `cat` to avoid flooding the context with large files. "
                    "Supports reading a line range (start_line..end_line) or the first N lines (max_lines)."
                ),
                "parameters": {
                    "type": "object",
                    "properties": {
                        "path": {
                            "type": "string",
                            "description": "Absolute path to the file, or relative path inside /workspace.",
                        },
                        "start_line": {
                            "type": "integer",
                            "description": "1-based line number to start reading from. Defaults to 1.",
                        },
                        "end_line": {
                            "type": "integer",
                            "description": "1-based line number to stop reading at (inclusive). If omitted, reads up to max_lines lines from start_line.",
                        },
                        "max_lines": {
                            "type": "integer",
                            "description": "Maximum number of lines to return when end_line is not specified. Defaults to 100.",
                        },
                    },
                    "required": ["path"],
                },
            },
        },
        {
            "type": "function",
            "function": {
                "name": "grep_file",
                "description": (
                    "Search for a string or regex pattern inside a file or recursively inside a directory. "
                    "Returns matching lines with surrounding context. "
                    "Use this to locate a class, function, or symbol before reading it with read_file_lines — "
                    "much cheaper than reading whole files."
                ),
                "parameters": {
                    "type": "object",
                    "properties": {
                        "path": {
                            "type": "string",
                            "description": "Absolute path to a file or directory to search. If a directory, searches recursively.",
                        },
                        "pattern": {
                            "type": "string",
                            "description": "String or Python regex pattern to search for.",
                        },
                        "context_lines": {
                            "type": "integer",
                            "description": "Number of lines of context to include before and after each match. Defaults to 3.",
                        },
                        "max_matches": {
                            "type": "integer",
                            "description": "Maximum number of matches to return. Defaults to 20.",
                        },
                    },
                    "required": ["path", "pattern"],
                },
            },
        },
        {
            "type": "function",
            "function": {
                "name": "workspace_detect_project",
                "description": "Inspect the sandbox workspace and infer project type, likely root directory, dependency manifests, and candidate install/test commands.",
                "parameters": {
                    "type": "object",
                    "properties": {
                        "cwd": {
                            "type": "string",
                            "description": "Optional directory inside /workspace to inspect. Defaults to the discovered repository root or /workspace.",
                        },
                    },
                    "required": [],
                },
            },
        },
        {
            "type": "function",
            "function": {
                "name": "workspace_run_tests",
                "description": "Detect a project in the workspace and run the most likely test command, optionally installing dependencies first.",
                "parameters": {
                    "type": "object",
                    "properties": {
                        "cwd": {"type": "string", "description": "Optional directory inside /workspace to use as the project root."},
                        "install": {"type": "boolean", "description": "Whether to install dependencies before running tests. Defaults to true."},
                        "test_command": {"type": "string", "description": "Optional explicit test command override."},
                        "timeout_seconds": {"type": "integer", "description": "Optional timeout in seconds for each command. Defaults to 900 and is capped at 1800."},
                    },
                    "required": [],
                },
            },
        },
        {
            "type": "function",
            "function": {
                "name": "workspace_run_command",
                "description": "Run a shell command inside the sandbox workspace to install dependencies, inspect files, and execute tests.",
                "parameters": {
                    "type": "object",
                    "properties": {
                        "command": {"type": "string", "description": "Shell command to run inside the sandbox workspace."},
                        "cwd": {"type": "string", "description": "Optional working directory inside /workspace. Defaults to /workspace."},
                        "timeout_seconds": {"type": "integer", "description": "Optional timeout in seconds. Defaults to 600 and is capped at 1800."},
                    },
                    "required": ["command"],
                },
            },
        },
    ]


def local_tool_names() -> set[str]:
    return {
        tool["function"]["name"]
        for tool in local_tools()
        if isinstance(tool, dict) and isinstance(tool.get("function"), dict) and isinstance(tool["function"].get("name"), str)
    }


def resolve_workspace_path(state: SandboxRuntimeState, raw_cwd: str | None) -> pathlib.Path:
    candidate = (raw_cwd or "").strip()
    if candidate == "":
        return state.known_workspace_dirs[-1] if state.known_workspace_dirs else state.workspace_root
    base = pathlib.Path(candidate)
    if not base.is_absolute():
        base = state.workspace_root / candidate
    resolved = base.resolve()
    if not str(resolved).startswith(str(state.workspace_root)):
        raise RuntimeError("Requested cwd escapes /workspace")
    if not resolved.exists():
        if state.known_workspace_dirs:
            fallback = state.known_workspace_dirs[-1]
            state.add_finding(f"Requested cwd {resolved} did not exist; used discovered workspace directory {fallback} instead.")
            state.record_action(
                "resolve_workspace",
                "Recovered missing cwd",
                "completed",
                details=f"redirected missing cwd {resolved} to {fallback}",
                evidence={"requested_cwd": str(resolved), "fallback_cwd": str(fallback)},
            )
            return fallback
        raise RuntimeError(f"Requested cwd does not exist: {resolved}")
    if not resolved.is_dir():
        raise RuntimeError(f"Requested cwd is not a directory: {resolved}")
    return resolved


def find_project_root(state: SandboxRuntimeState, start_dir: pathlib.Path) -> pathlib.Path:
    markers = ["composer.json", "package.json", "pyproject.toml", "requirements.txt", "go.mod", "Cargo.toml", "pom.xml", "build.gradle", "build.gradle.kts"]
    candidates = [start_dir, *state.known_workspace_dirs[::-1], state.workspace_root]
    seen: set[pathlib.Path] = set()
    for candidate in candidates:
        for current in [candidate, *candidate.parents]:
            resolved = current.resolve()
            if resolved in seen:
                continue
            seen.add(resolved)
            if not str(resolved).startswith(str(state.workspace_root)):
                continue
            if any((resolved / marker).exists() for marker in markers):
                return resolved
    return start_dir


def detect_project(state: SandboxRuntimeState, cwd: pathlib.Path) -> dict[str, Any]:
    root = find_project_root(state, cwd)
    manifests = {
        "composer_json": (root / "composer.json").exists(),
        "package_json": (root / "package.json").exists(),
        "pyproject_toml": (root / "pyproject.toml").exists(),
        "requirements_txt": (root / "requirements.txt").exists(),
        "go_mod": (root / "go.mod").exists(),
        "cargo_toml": (root / "Cargo.toml").exists(),
        "pom_xml": (root / "pom.xml").exists(),
        "build_gradle": (root / "build.gradle").exists() or (root / "build.gradle.kts").exists(),
    }
    frameworks: list[str] = []
    install_commands: list[str] = []
    test_commands: list[str] = []
    project_types: list[str] = []

    if manifests["composer_json"]:
        project_types.append("php")
        install_commands.append("composer install --no-interaction --prefer-dist")
        if (root / "artisan").exists():
            frameworks.append("laravel")
            test_commands.extend(["php artisan test", "vendor/bin/phpunit"])
        else:
            test_commands.append("vendor/bin/phpunit")

    if manifests["package_json"]:
        project_types.append("node")
        install_commands.extend(["npm ci", "npm install"])
        try:
            package_json = json.loads((root / "package.json").read_text())
        except Exception:
            package_json = {}
        scripts = package_json.get("scripts") if isinstance(package_json, dict) else {}
        if isinstance(scripts, dict):
            if "test" in scripts:
                test_commands.append("npm test -- --runInBand")
            if "test:unit" in scripts:
                test_commands.append("npm run test:unit")
            if "vitest" in json.dumps(scripts).lower():
                test_commands.append("npx vitest run")

    if manifests["pyproject_toml"] or manifests["requirements_txt"]:
        project_types.append("python")
        if manifests["pyproject_toml"]:
            install_commands.extend(["pip install -e .", "pip install -r requirements.txt"])
        elif manifests["requirements_txt"]:
            install_commands.append("pip install -r requirements.txt")
        test_commands.extend(["pytest", "python -m pytest"])

    if manifests["go_mod"]:
        project_types.append("go")
        test_commands.append("go test ./...")
    if manifests["cargo_toml"]:
        project_types.append("rust")
        test_commands.append("cargo test")
    if manifests["pom_xml"] or manifests["build_gradle"]:
        project_types.append("java")
        if manifests["pom_xml"]:
            test_commands.append("mvn test")
        if manifests["build_gradle"]:
            test_commands.append("./gradlew test")

    result = {
        "success": True,
        "project_root": state.workspace_relative(root),
        "project_types": list(dict.fromkeys(project_types)),
        "frameworks": list(dict.fromkeys(frameworks)),
        "manifests": manifests,
        "install_commands": list(dict.fromkeys(install_commands)),
        "test_commands": list(dict.fromkeys(test_commands)),
    }
    state.add_finding(
        "Detected project root "
        f"{result['project_root']} with project types: "
        + (", ".join(result["project_types"]) if result["project_types"] else "unknown")
        + "."
    )
    state.record_action(
        "detect_project",
        "Detected workspace project",
        "completed",
        details=f"root={result['project_root']}, types={', '.join(result['project_types']) or 'unknown'}",
        evidence={"project_root": result["project_root"], "project_types": result["project_types"], "frameworks": result["frameworks"], "manifests": manifests},
    )
    state.register_workspace_dir(root)
    return result


def trim_command_output(text: str) -> str:
    if len(text) <= MAX_COMMAND_OUTPUT_CHARS:
        return text
    warning = (
        f"[WARNING: output truncated from {len(text)} to {MAX_COMMAND_OUTPUT_CHARS} chars. "
        "Use read_file_lines or grep_file tools for targeted file reading instead of cat.]\n"
    )
    return warning + text[:MAX_COMMAND_OUTPUT_CHARS] + "\n...[truncated]"


def command_kind(command: str) -> str:
    lowered = command.lower()
    if any(token in lowered for token in ["php artisan test", "phpunit", "pytest", "vitest", "jest", "go test", "cargo test", "mvn test", "gradle test", "npm test"]):
        return "run_tests"
    if any(token in lowered for token in ["composer install", "composer update", "npm ci", "npm install", "pip install", "poetry install", "bundle install", "pnpm install", "yarn install"]):
        return "install_dependencies"
    if any(token in lowered for token in ["git ", "ls ", "find ", "cat ", "sed ", "rg ", "grep "]):
        return "inspect_workspace"
    return "run_command"


def install_commands_for_test(project: dict[str, Any], test_command: str) -> list[str]:
    manifests = project.get("manifests") if isinstance(project.get("manifests"), dict) else {}
    all_install_commands = [
        command
        for command in project.get("install_commands", [])
        if isinstance(command, str) and command.strip() != ""
    ]
    lowered = test_command.lower()

    if any(token in lowered for token in ["php artisan test", "phpunit"]) and manifests.get("composer_json"):
        return [command for command in all_install_commands if command.startswith("composer ")]
    if any(token in lowered for token in ["npm ", "npx ", "vitest", "jest"]) and manifests.get("package_json"):
        return [command for command in all_install_commands if command.startswith("npm ")]
    if any(token in lowered for token in ["pytest", "python -m pytest"]) and (manifests.get("pyproject_toml") or manifests.get("requirements_txt")):
        return [command for command in all_install_commands if command.startswith("pip ")]
    if "go test" in lowered and manifests.get("go_mod"):
        return []
    if "cargo test" in lowered and manifests.get("cargo_toml"):
        return []
    if any(token in lowered for token in ["mvn test", "./gradlew test"]) and (manifests.get("pom_xml") or manifests.get("build_gradle")):
        return []

    return all_install_commands


def parse_test_metrics(command: str, stdout: str, stderr: str, exit_code: int | None) -> dict[str, Any]:
    combined = f"{stdout}\n{stderr}"
    metrics: dict[str, Any] = {"command": command, "exit_code": exit_code}
    phpunit = re.search(r"Tests:\s+(\d+)(?:,\s+Assertions:\s+(\d+))?(?:,\s+Failures:\s+(\d+))?(?:,\s+Errors:\s+(\d+))?(?:,\s+Skipped:\s+(\d+))?", combined)
    if phpunit:
        metrics["tests"] = int(phpunit.group(1))
        if phpunit.group(2):
            metrics["assertions"] = int(phpunit.group(2))
        if phpunit.group(3):
            metrics["failures"] = int(phpunit.group(3))
        if phpunit.group(4):
            metrics["errors"] = int(phpunit.group(4))
        if phpunit.group(5):
            metrics["skipped"] = int(phpunit.group(5))
    pytest = re.search(r"=+\s+(.+?)\s+in\s+[0-9.]+s\s+=+", combined)
    if pytest:
        summary_line = pytest.group(1)
        for label in ["passed", "failed", "skipped", "error", "errors", "xfailed", "xpassed"]:
            match = re.search(rf"(\d+)\s+{label}", summary_line)
            if match:
                metrics[label.replace("errors", "error_count")] = int(match.group(1))
    if "tests" not in metrics:
        generic = re.search(r"Ran\s+(\d+)\s+tests?", combined)
        if generic:
            metrics["tests"] = int(generic.group(1))
    return metrics


def build_test_summary(metrics: dict[str, Any], timed_out: bool) -> str:
    if timed_out:
        return "Test command timed out."
    parts: list[str] = []
    for key in ["tests", "assertions", "failures", "errors", "passed", "failed", "skipped"]:
        value = metrics.get(key)
        if isinstance(value, int):
            parts.append(f"{key}={value}")
    if parts:
        return ", ".join(parts)
    exit_code = metrics.get("exit_code")
    return f"exit_code={exit_code}" if exit_code is not None else "test command completed"


def _execute_workspace_command(state: SandboxRuntimeState, command: str, cwd: pathlib.Path, timeout_seconds: int) -> dict[str, Any]:
    state.log(f"Running local workspace command in {cwd}: {command}")
    env = os.environ.copy()
    env["CI"] = "1"
    env["HOME"] = env.get("HOME", "/tmp")
    try:
        completed = subprocess.run(
            ["sh", "-lc", command],
            cwd=str(cwd),
            capture_output=True,
            text=True,
            timeout=timeout_seconds,
            env=env,
        )
    except subprocess.TimeoutExpired as exc:
        state.log(f"Local command timed out after {timeout_seconds}s: {command}")
        raw_stdout = exc.stdout if exc.stdout is not None else b""
        raw_stderr = exc.stderr if exc.stderr is not None else b""
        stdout = trim_command_output(raw_stdout.decode("utf-8", errors="replace") if isinstance(raw_stdout, bytes) else raw_stdout)
        stderr = trim_command_output(raw_stderr.decode("utf-8", errors="replace") if isinstance(raw_stderr, bytes) else raw_stderr)
        stdout_artifact = state.write_artifact("command-stdout", stdout, description=f"stdout for command: {command}")
        stderr_artifact = state.write_artifact("command-stderr", stderr, description=f"stderr for command: {command}")
        details = build_test_summary({"command": command, "exit_code": None}, True) if command_kind(command) == "run_tests" else "command timed out"
        if command_kind(command) == "run_tests":
            state.add_finding(f"Test command timed out: {command}")
        state.record_action(
            command_kind(command),
            f"Ran command: {command}",
            "failed",
            details=details,
            evidence={"cwd": str(cwd), "command": command, "timed_out": True, "stdout_artifact": stdout_artifact["path"] if stdout_artifact else None, "stderr_artifact": stderr_artifact["path"] if stderr_artifact else None},
        )
        return {"success": False, "cwd": str(cwd), "command": command, "timed_out": True, "exit_code": None, "stdout": stdout, "stderr": stderr, "error": f"Command timed out after {timeout_seconds} seconds"}

    state.log(f"Local command finished with exit_code={completed.returncode}: {command}")
    stdout = trim_command_output(completed.stdout)
    stderr = trim_command_output(completed.stderr)
    stdout_artifact = state.write_artifact("command-stdout", stdout, description=f"stdout for command: {command}")
    stderr_artifact = state.write_artifact("command-stderr", stderr, description=f"stderr for command: {command}")
    kind = command_kind(command)
    details = f"exit_code={completed.returncode}"
    evidence: dict[str, Any] = {"cwd": str(cwd), "command": command, "exit_code": completed.returncode}
    if stdout_artifact:
        evidence["stdout_artifact"] = stdout_artifact["path"]
    if stderr_artifact:
        evidence["stderr_artifact"] = stderr_artifact["path"]
    if kind == "run_tests":
        metrics = parse_test_metrics(command, stdout, stderr, completed.returncode)
        details = build_test_summary(metrics, False)
        evidence.update(metrics)
        state.add_finding(f"Test command {'succeeded' if completed.returncode == 0 else 'failed'} ({details}): {command}")
    elif kind == "install_dependencies":
        state.add_finding(f"Dependency install command exited with code {completed.returncode}: {command}")
    state.record_action(kind, f"Ran command: {command}", "completed" if completed.returncode == 0 else "failed", details=details, evidence=evidence)
    return {"success": completed.returncode == 0, "cwd": str(cwd), "command": command, "timed_out": False, "exit_code": completed.returncode, "stdout": stdout, "stderr": stderr}


def _resolve_file_path(state: SandboxRuntimeState, raw_path: str) -> pathlib.Path:
    path = pathlib.Path(raw_path)
    if not path.is_absolute():
        path = state.workspace_root / raw_path
    resolved = path.resolve()
    if not str(resolved).startswith(str(state.workspace_root)):
        raise RuntimeError("Requested path escapes /workspace")
    return resolved


def execute_read_file_lines(state: SandboxRuntimeState, arguments: dict[str, Any]) -> dict[str, Any]:
    raw_path = str(arguments.get("path") or "").strip()
    if not raw_path:
        return {"success": False, "error": "path is required"}
    file_path = _resolve_file_path(state, raw_path)
    if not file_path.exists():
        return {"success": False, "error": f"File not found: {file_path}"}
    if not file_path.is_file():
        return {"success": False, "error": f"Path is not a file: {file_path}"}

    start_line = max(1, int(arguments.get("start_line") or 1))
    end_line = arguments.get("end_line")
    max_lines = max(1, int(arguments.get("max_lines") or 100))
    if end_line is not None:
        end_line = int(end_line)
        limit = end_line - start_line + 1
    else:
        limit = max_lines

    collected: list[str] = []
    total_lines = 0
    try:
        with file_path.open("r", encoding="utf-8", errors="replace") as fh:
            for i, line in enumerate(fh, start=1):
                total_lines += 1
                if i < start_line:
                    continue
                if len(collected) < limit:
                    collected.append(line.rstrip("\n"))
    except Exception as exc:
        return {"success": False, "error": str(exc)}

    actual_end = start_line + len(collected) - 1
    truncated = (end_line is None and total_lines > start_line + limit - 1) or (end_line is not None and end_line > total_lines)
    return {
        "success": True,
        "path": str(state.workspace_relative(file_path)),
        "total_lines": total_lines,
        "start_line": start_line,
        "end_line": actual_end,
        "lines": collected,
        "truncated": truncated,
    }


def execute_grep_file(state: SandboxRuntimeState, arguments: dict[str, Any]) -> dict[str, Any]:
    raw_path = str(arguments.get("path") or "").strip()
    pattern = str(arguments.get("pattern") or "").strip()
    if not raw_path:
        return {"success": False, "error": "path is required"}
    if not pattern:
        return {"success": False, "error": "pattern is required"}

    search_path = _resolve_file_path(state, raw_path)
    if not search_path.exists():
        return {"success": False, "error": f"Path not found: {search_path}"}

    context_lines = max(0, int(arguments.get("context_lines") or 3))
    max_matches = max(1, int(arguments.get("max_matches") or 20))

    try:
        compiled = re.compile(pattern)
    except re.error as exc:
        return {"success": False, "error": f"Invalid regex pattern: {exc}"}

    def is_binary(path: pathlib.Path) -> bool:
        try:
            chunk = path.read_bytes()[:1024]
            return b"\x00" in chunk
        except Exception:
            return True

    def grep_single_file(path: pathlib.Path) -> list[dict[str, Any]]:
        if is_binary(path):
            return []
        try:
            lines = path.read_text(encoding="utf-8", errors="replace").splitlines()
        except Exception:
            return []
        results = []
        for i, line in enumerate(lines):
            if compiled.search(line):
                before = lines[max(0, i - context_lines):i]
                after = lines[i + 1:i + 1 + context_lines]
                results.append({
                    "file": str(state.workspace_relative(path)),
                    "line_number": i + 1,
                    "content": line,
                    "context_before": before,
                    "context_after": after,
                })
        return results

    matches: list[dict[str, Any]] = []
    truncated = False
    if search_path.is_file():
        matches = grep_single_file(search_path)
        if len(matches) > max_matches:
            matches = matches[:max_matches]
            truncated = True
    else:
        for file_path in sorted(search_path.rglob("*")):
            if not file_path.is_file():
                continue
            for match in grep_single_file(file_path):
                matches.append(match)
                if len(matches) >= max_matches:
                    truncated = True
                    break
            if truncated:
                break

    return {
        "success": True,
        "pattern": pattern,
        "total_matches": len(matches),
        "truncated": truncated,
        "matches": matches,
    }


def execute_local_tool(state: SandboxRuntimeState, tool_name: str, arguments: dict[str, Any] | None = None) -> Any:
    arguments = arguments or {}
    if tool_name == "read_file_lines":
        return execute_read_file_lines(state, arguments)

    if tool_name == "grep_file":
        return execute_grep_file(state, arguments)

    if tool_name == "workspace_detect_project":
        cwd = resolve_workspace_path(state, arguments.get("cwd") if isinstance(arguments.get("cwd"), str) else None)
        return detect_project(state, cwd)

    if tool_name == "workspace_run_tests":
        cwd = resolve_workspace_path(state, arguments.get("cwd") if isinstance(arguments.get("cwd"), str) else None)
        project = detect_project(state, cwd)
        project_root = resolve_workspace_path(state, project.get("project_root") if isinstance(project.get("project_root"), str) else None)
        timeout_seconds = max(1, min(1800, int(arguments.get("timeout_seconds") or 900)))
        should_install = bool(arguments.get("install", True))
        explicit_test_command = str(arguments.get("test_command") or "").strip()
        install_results: list[dict[str, Any]] = []

        candidate_commands: list[str] = []
        if explicit_test_command != "":
            candidate_commands.append(explicit_test_command)
        candidate_commands.extend([command for command in project.get("test_commands", []) if isinstance(command, str) and command.strip() != "" and command not in candidate_commands])
        if not candidate_commands:
            state.record_action("run_tests", "Executed structured test workflow", "blocked", details="No candidate test command could be inferred for the detected project.", evidence={"project_root": project.get("project_root"), "project_types": project.get("project_types", [])})
            state.add_finding("Project was detected, but no test command could be inferred.")
            return {"success": False, "project": project, "install_results": install_results, "test_result": None, "error": "No candidate test command could be inferred for the detected project"}

        last_result: dict[str, Any] | None = None
        attempted_commands: list[str] = []
        attempted_install_commands: list[str] = []
        install_cache: dict[str, dict[str, Any]] = {}
        for command in candidate_commands:
            if should_install:
                required_install_commands = install_commands_for_test(project, command)
                install_failed = False
                for install_command in required_install_commands:
                    attempted_install_commands.append(install_command)
                    cached = install_cache.get(install_command)
                    if cached is None:
                        cached = _execute_workspace_command(state, install_command, project_root, timeout_seconds)
                        install_cache[install_command] = cached
                        install_results.append(cached)
                    if not cached.get("success"):
                        install_failed = True
                        last_result = cached
                        break
                if install_failed:
                    state.record_action(
                        "run_tests",
                        "Executed structured test workflow",
                        "failed",
                        details=f"dependency installation failed before tests: {install_command}",
                        evidence={
                            "project_root": project.get("project_root"),
                            "install_command": install_command,
                            "attempted_test_commands": attempted_commands,
                            "attempted_install_commands": attempted_install_commands,
                        },
                    )
                    continue
            attempted_commands.append(command)
            result = _execute_workspace_command(state, command, project_root, timeout_seconds)
            last_result = result
            if result.get("success"):
                state.record_action(
                    "run_tests",
                    "Executed structured test workflow",
                    "completed",
                    details=f"selected test command: {command}",
                    evidence={
                        "project_root": project.get("project_root"),
                        "attempted_test_commands": attempted_commands,
                        "attempted_install_commands": attempted_install_commands,
                    },
                )
                state.add_finding(f"Structured test workflow succeeded with command: {command}")
                return {"success": True, "project": project, "install_results": install_results, "attempted_test_commands": attempted_commands, "test_result": result}

        state.record_action(
            "run_tests",
            "Executed structured test workflow",
            "failed",
            details="all inferred test commands failed",
            evidence={
                "project_root": project.get("project_root"),
                "attempted_test_commands": attempted_commands,
                "attempted_install_commands": attempted_install_commands,
            },
        )
        state.add_finding("Structured test workflow exhausted inferred test commands without success.")
        return {"success": False, "project": project, "install_results": install_results, "attempted_test_commands": attempted_commands, "test_result": last_result, "error": "All inferred test commands failed"}

    if tool_name != "workspace_run_command":
        raise RuntimeError(f"Unsupported local tool '{tool_name}'")

    command = str(arguments.get("command") or "").strip()
    if command == "":
        return {"success": False, "error": "command is required"}
    cwd = resolve_workspace_path(state, arguments.get("cwd") if isinstance(arguments.get("cwd"), str) else None)
    timeout_seconds = max(1, min(1800, int(arguments.get("timeout_seconds") or 600)))
    return _execute_workspace_command(state, command, cwd, timeout_seconds)
