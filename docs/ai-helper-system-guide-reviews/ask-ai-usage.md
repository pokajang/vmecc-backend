# Review dossier: ask-ai-usage

Status: candidate authored; System Administration approval pending.

1. Frontend route/guard: global header panel; authenticated application shell.
2. Page and labels: `AiHelperPanel.js`; **Chat**, **History**, **Knowledge**, **Send**, **Report**.
3. API: `GET /api/ai-helper/context|threads|thread|documents`, `POST /api/ai-helper/messages/stream`, `POST /api/ai-helper/messages/{messageId}/report`, `DELETE /api/ai-helper/threads/{threadId}`.
4. Controller/request: `AiHelperController`; `StreamAiHelperMessageRequest`; `ReportAiHelperMessageRequest`.
5. Access: authenticated session, CSRF, maintenance middleware; knowledge audiences are filtered server-side.
6. Module: Profile gate for the global safe guide.
7. Validation: message required, string, configured maximum 2,000; language auto/en/bm; purpose chat/embedded_helper.
8. State/next actor: stream produces answer or explicit failure; user verifies sources and performs the action.
9. Attachments: chat has no attachment input; Knowledge exposes approved PDF documents only.
10. Recovery: generation error remains non-actioning; user retries once or contacts the guide owner.
11. Tests: `AiHelperApiTest.php`, `AiHelperSystemGuideRetrievalTest.php`, frontend `AiHelperPanel.test.jsx` and `MessageBubble.test.jsx`.
12. Discrepancies: generic template removed; guide distinguishes application guidance from operational policy.
13. Approval: owner System Administration; v3 body; approval reference, approver, date, and SHA-256 pending.
