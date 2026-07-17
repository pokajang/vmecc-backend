# Wave A system-guide approval packet

Date prepared: 2026-07-17

Release state: disabled v3 candidates; not approved

Review each guide together with its same-name dossier. Approval is valid only when an accountable owner supplies an approval reference, approver identity, and approval date for the exact SHA-256 below.

| Guide | Owner | Normalized body SHA-256 |
|---|---|---|
| `ask-ai-usage` | System Administration | `cc9825efff3697f2302c86a9e64630f3fee1d573c695555f25cc72681b1c237a` |
| `dashboard-basics` | System Administration | `742e91a36ec2089f65c3df8c437f1baf4d1bc61967add2106f655dadd4001f2a` |
| `profile-security` | System Administration | `0617d8decfd13e1f3cb579711de1c49280e9440cff80f131ea97a5e0b7ede083` |
| `profile-banking` | Human Resources | `9f58f75ffcbc854550c5ba37c3cbc7aaa2c08500e5d5bd75262bb5ed1eb4c3cf` |
| `profile-medical` | Human Resources | `fc9fdda0bc613797ec4af00e744bedda41012222ac36b3faf681754982643717` |
| `profile-emergency` | Human Resources | `f6d52a26df0f800a2534cbec85b1c020566a4865290ba485e76c1834800d2bf4` |
| `messages` | System Administration | `79e5ee4973d798d0a19e25e61689100b2f4c75fb53dce77dd052f2822979447c` |

Required owner response per guide:

- approval reference or ticket;
- approved-by account/name;
- approval date;
- explicit decision: approve this hash or return changes.

Any Markdown body change invalidates the listed hash and requires a new review. Do not edit `approvals.json`, set `release_status: approved`, or set `active: true` until the corresponding owner response is recorded.
