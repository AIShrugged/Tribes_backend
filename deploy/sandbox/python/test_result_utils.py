from __future__ import annotations

import pathlib
import sys
import unittest

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))

from result_utils import build_fallback_result, normalize_final_content
from runtime_state import SandboxRuntimeState


class ResultUtilsTest(unittest.TestCase):
    def test_normalize_final_content_preserves_plan_and_handoff(self) -> None:
        state = SandboxRuntimeState()
        raw = """
        {
          "output": "Created a follow-up task for the PR step.",
          "summary": "Workspace scan completed.",
          "blocker": null,
          "actions": [],
          "findings": ["Detected pending PR work."],
          "artifacts": [],
          "memory_candidates": [],
          "plan": ["Scan workspace", "Create follow-up task", "Stop after bounded deliverable"],
          "handoff": {
            "reason": "PR creation should happen in a separate run.",
            "target": "followup_task",
            "context_summary": "Workspace scan completed and the next step is to create the PR.",
            "status": "created",
            "followup_task_id": "17"
          }
        }
        """

        normalized = normalize_final_content(state, raw)

        self.assertEqual(
            ["Scan workspace", "Create follow-up task", "Stop after bounded deliverable"],
            normalized["plan"],
        )
        self.assertEqual("followup_task", normalized["handoff"]["target"])
        self.assertEqual(17, normalized["handoff"]["followup_task_id"])

    def test_fallback_result_includes_empty_plan_and_handoff(self) -> None:
        state = SandboxRuntimeState()

        result = build_fallback_result(state, "Agent loop reached max iterations.")

        self.assertEqual([], result["plan"])
        self.assertIsNone(result["handoff"])


if __name__ == "__main__":
    unittest.main()
