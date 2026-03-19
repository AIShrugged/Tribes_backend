from __future__ import annotations

import json
import pathlib
import sys
import unittest

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))

import runner
from runtime_state import SandboxRuntimeState


class RunnerFinalizationTest(unittest.TestCase):
    def setUp(self) -> None:
        runner.STATE = SandboxRuntimeState()
        runner.STATE.record_action("scan_workspace", "Scanned workspace", "completed")
        runner.STATE.add_finding("Found the secret word in the workspace.")

    def test_attempt_finalization_returns_normalized_json(self) -> None:
        def fake_call_llm(gateway, messages, system_prompt, max_tokens=2048, include_tools=True, extra_tools=None):  # noqa: ANN001
            self.assertFalse(include_tools)
            payload = json.loads(messages[0]["content"].split("\n\n", 1)[1])
            self.assertEqual("Scanned workspace", payload["actions"][0]["title"])
            return {
                "role": "assistant",
                "content": json.dumps({
                    "output": "alpha",
                    "summary": "Returned the secret word.",
                    "blocker": None,
                    "actions": payload["actions"],
                    "findings": payload["findings"],
                    "artifacts": [],
                    "memory_candidates": [],
                    "plan": ["Scan workspace", "Return the word"],
                    "handoff": None,
                }),
            }

        original_call_llm = runner.call_llm
        try:
            runner.call_llm = fake_call_llm
            result = runner.attempt_finalization({"base_url": "http://app"}, {"id": 1, "run_id": 2, "name": "Read word"}, "partial")
        finally:
            runner.call_llm = original_call_llm

        self.assertEqual("alpha", result["output"].splitlines()[0])
        self.assertEqual(["Scan workspace", "Return the word"], result["plan"])


if __name__ == "__main__":
    unittest.main()
