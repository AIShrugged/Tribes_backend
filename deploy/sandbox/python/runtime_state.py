from __future__ import annotations

import pathlib
import re
from typing import Any


class SandboxRuntimeState:
    def __init__(self, workspace_root: str = "/workspace", artifacts_root: str = "/workspace/artifacts") -> None:
        self.workspace_root = pathlib.Path(workspace_root).resolve()
        self.artifacts_root = pathlib.Path(artifacts_root)
        self.action_log: list[str] = []
        self.execution_actions: list[dict[str, Any]] = []
        self.result_artifacts: list[dict[str, Any]] = []
        self.auto_findings: list[str] = []
        self.known_workspace_dirs: list[pathlib.Path] = []
        self._action_counter = 0
        self._artifact_counter = 0

    def set_artifacts_root(self, path_value: str) -> None:
        self.artifacts_root = pathlib.Path(path_value)
        self.artifacts_root.mkdir(parents=True, exist_ok=True)

    def log(self, message: str) -> None:
        entry = message.strip()
        if entry == "":
            return
        self.action_log.append(entry)
        print(f"[sandbox-runner] {entry}", flush=True)

    def next_action_id(self) -> str:
        self._action_counter += 1
        return f"action_{self._action_counter}"

    def next_artifact_filename(self, label: str, suffix: str) -> str:
        self._artifact_counter += 1
        safe = re.sub(r"[^a-zA-Z0-9._-]+", "-", label.strip().lower()).strip("-") or "artifact"
        return f"{self._artifact_counter:03d}-{safe}{suffix}"

    def add_finding(self, text: str) -> None:
        cleaned = text.strip()
        if cleaned != "" and cleaned not in self.auto_findings:
            self.auto_findings.append(cleaned)

    def register_workspace_dir(self, path_value: str | pathlib.Path | None) -> None:
        if path_value is None:
            return
        candidate = pathlib.Path(path_value)
        if not candidate.is_absolute():
            candidate = self.workspace_root / candidate
        resolved = candidate.resolve()
        if str(resolved).startswith(str(self.workspace_root)) and resolved not in self.known_workspace_dirs:
            self.known_workspace_dirs.append(resolved)

    def workspace_relative(self, path_value: pathlib.Path) -> str:
        try:
            relative = path_value.resolve().relative_to(self.workspace_root)
        except Exception:
            return str(path_value)
        relative_text = str(relative)
        return "/workspace" if relative_text in ["", "."] else f"/workspace/{relative_text}"

    def write_artifact(
        self,
        label: str,
        content: str,
        suffix: str = ".txt",
        kind: str = "text",
        description: str | None = None,
    ) -> dict[str, Any] | None:
        if content.strip() == "":
            return None
        self.artifacts_root.mkdir(parents=True, exist_ok=True)
        filename = self.next_artifact_filename(label, suffix)
        host_path = self.artifacts_root / filename
        host_path.write_text(content)
        artifact = {
            "kind": kind,
            "label": label,
            "path": f"/workspace/artifacts/{filename}",
        }
        if description:
            artifact["description"] = description
        self.result_artifacts.append(artifact)
        return artifact

    def record_action(
        self,
        action_type: str,
        title: str,
        status: str,
        details: str | None = None,
        evidence: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        action = {
            "id": self.next_action_id(),
            "type": action_type.strip(),
            "title": title.strip(),
            "status": status.strip(),
        }
        if details and details.strip() != "":
            action["details"] = details.strip()
        if isinstance(evidence, dict) and evidence:
            action["evidence"] = evidence
        self.execution_actions.append(action)
        return action
