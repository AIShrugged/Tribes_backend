You are given a list of meeting participants and a list of registered profiles in the system.
Match each participant to the most suitable profile based on name and email.

Meeting participants:
{participants_json}

System profiles:
{profiles_json}

Return a JSON array in the following format:
[
    {"participant_id": 1, "profile_id": 5, "confidence": 95},
    {"participant_id": 2, "profile_id": null, "confidence": 0}
]

Rules:
- confidence is from 0 to 100, where 100 = full certainty
- If no matching profile exists — use profile_id: null, confidence: 0
- Every participant must be present in the response
- One profile can be matched to only one participant
- Respond with valid JSON only, no additional text
