# Domain Boundaries (Bounded Contexts)

> Master prompt §3.2. Status: Proposed. "Absorbs" lists the existing code each context takes over; nothing is deleted until its replacement is tested.
> Event names: `context.entity.verb_past` (e.g. `work.task.assigned`). Payloads carry IDs, never full records or message bodies.

## Context map

```
 Identity&Access ──► everyone (user id, permission checks)
 Organization ─────► Work, Conversations(routing), Workflow(approvers), Analytics
 Customer Identity ◄─ Channels (identities)      ◄─ CRM (profiles reference person_id)
 Conversations ◄──── Channels (inbound)  ──► Notifications (outbound replies go through it)
 CRM ──────────────► Work (follow-up tasks, via Work Api only)
 Work ◄────────────► Workflow&Approvals (state machines, approvals, SLA timers)
 Automation ◄─────── events from all contexts ──► commands to Work/CRM/Conversations/Notifications
 Notifications ────► Channels (connectors)
 Audit ◄──────────── events from all contexts (append-only)
 Tenant&Subscription ► Entitlements read by all; Platform Admin operates it
```

Upstream/downstream rule: a context calls another only through `Eb\<X>\Api`; it never reads another context's tables. Reporting (Analytics) is the one exception: read-only, via declared views.

---

## 1. Identity & Access
| | |
|---|---|
| Owns | users (`eb_users`), credentials, sessions, API keys (new `eb_api_keys`), roles & permission sets (new `eb_roles_perm`, see permission-matrix), login throttle state, MFA secrets (later) |
| References | Organization units (for scoped role grants) |
| Commands | `createUser`, `deactivateUser`, `grantRole(user, role, scope)`, `revokeRole`, `issueApiKey`, `login`, `logout`, `impersonate` |
| Events | `identity.user.created/deactivated`, `identity.role.granted/revoked`, `identity.login.succeeded/failed`, `identity.impersonation.started/stopped` |
| API | `IdentityApi::can(userId, permission, scope)`, `currentUser()` |
| Failure | Permission service unavailable ⇒ deny (fail closed). DB unreachable during session refresh ⇒ keep session (2.1.6 behaviour). |
| Retention | Users kept (deactivated) for audit; login events 1 year |
| Absorbs | `core/Auth.php`, `core/Viewer.php`, `core/ApiPolicy.php`, `core/LoginThrottle.php`, `modules/OrgRoles.php` (permission half), CRM `users`/`auth_tokens` (retired, bridged already) |

## 2. Organization
| | |
|---|---|
| Owns | org units (new `eb_org_units`: type division/department/team, parent, manager, effective_from/to), memberships (new `eb_org_memberships`: user, unit, role_in_unit, primary, from/to), delegations (acting manager), working calendars & holidays |
| References | users |
| Commands | `createUnit`, `moveUnit` (cycle check), `setManager`, `addMember`, `endMembership`, `delegate(from, to, until)` |
| Events | `org.unit.created/moved/archived`, `org.member.added/removed`, `org.manager.changed`, `org.delegation.started/ended` |
| Failure | Cycle or overlapping effective dates ⇒ validation error, no partial write |
| Retention | History kept indefinitely (effective-dated rows, never overwritten) |
| Absorbs | org-chart hierarchy half of `eb_roles` (OrgRoles), `eb_teams` (inbox teams become org units of type team or routing groups referencing them), CRM `staff.unit` / `staff.supervisor_id`, marketing `eb_mkt_roles` tree |

## 3. Work (Projects & Tasks)
| | |
|---|---|
| Owns | workspaces, projects, boards, lists/states, tasks, subtasks, assignees/watchers/collaborators, comments (public/internal), checklists, attachments refs, dependencies, links to external entities, time entries, custom field values, saved views |
| References | users, org units, Workflow definitions, Files, CRM entities (by typed reference `{type:'crm.lead', id}`) |
| Commands | `createTask`, `updateTask` (optimistic version), `moveTask(state, position)`, `assign`, `addComment`, `addDependency` (cycle check), `logTime`, `routeToUnit`, `acceptRouting`, `rejectRouting` |
| Events | `work.task.created/updated/moved/assigned/completed/reopened`, `work.task.routed/accepted/rejected`, `work.comment.added`, `work.dependency.added` |
| Failure | Concurrent edit ⇒ `409 conflict` with current version; WIP limit exceeded ⇒ `422` unless override permission |
| Retention | Archived not deleted; history append-only |
| Absorbs | CRM `tasks`, `projects` (migrated, ADR-007), marketing content kanban `eb_mkt_content*` (later, as a board template) |

## 4. Workflow & Approvals
| | |
|---|---|
| Owns | versioned workflow definitions (states, transitions, guards, required fields), workflow instances bound to a subject (task, lead…), approval requests/steps/decisions, SLA policies, business calendars use, timers |
| References | Organization (approvers by role/unit), Identity (permissions), subject entity by typed reference |
| Commands | `startInstance(definitionVersion, subject)`, `transition(instance, to, actor)`, `requestApproval`, `decide(approve/reject/request_changes)`, `escalate` |
| Events | `workflow.transitioned`, `approval.requested/approved/rejected/expired/escalated`, `sla.warning`, `sla.breached` |
| Failure | Guard failure ⇒ transition refused with reasons; timers driven by cron (`eb_jobs`), so a missed minute delays but never skips |
| Constraint | No user scripts. Guards are a declarative condition model (reuse `Flows::evalRules` operators). |
| Absorbs | nothing existing (new); conversation SLA logic in `Desk` later |

## 5. Customer Identity
| | |
|---|---|
| Owns | person master (`eb_persons`), channel identities (`eb_identities`), verified phones/emails, merge lineage (new `eb_person_merges`), match candidates review queue (new `eb_match_candidates`), consent & communication preferences (new `eb_consents`) |
| References | — (most upstream customer context) |
| Commands | `ingestIdentity(channel, uid, hints)`, `attachPhone(person, phone, verified)`, `proposeMerge`, `merge(a, b, actor)`, `unmerge(mergeId)`, `setConsent` |
| Events | `customer.person.created/updated/merged/unmerged`, `customer.identity.linked`, `customer.consent.changed` |
| Failure | Ambiguous match ⇒ candidate row, never auto-merge; merge is one transaction with lineage |
| Retention | Merge lineage forever; consent history forever (legal evidence) |
| Absorbs | `modules/Persons.php` (golden identity), `Groups`, tags, `core/PhoneNumber.php` |

## 6. CRM (Leads, Customers, Pipeline)
| | |
|---|---|
| Owns | lead records & pipeline stages (configurable), lead status history, customer business profile (level, value, notes), lost reasons, classifications/custom fields, segments, scoring models & scores, call records (telephony) |
| References | `person_id` (Customer Identity) — **replaces the phone10 join** (`eb_crm_links`); users/units for ownership |
| Commands | `createLead`, `qualify`, `changeStage`, `convertToCustomer`, `markLost(reason)`, `assignOwner`, `defineSegment`, `scoreRecompute` |
| Events | `crm.lead.created/stage_changed/converted/lost`, `crm.owner.changed`, `crm.segment.membership_changed`, `crm.call.recorded` |
| Failure | Conversion preserves the lead row and links the customer; never deletes history |
| Absorbs | CRM generic parts: `leads`, `lead_status_history`, `lead_references`, `customers`, `customer_notes`, `calls`/Telefonchy (telephony via Channels connector), `tickets` (later → Conversations or Work, decision in M4). **Jakojast-only** (`hosts`, `host_*`, `site_inquiries`, `site_sales`) → `extensions/jakojast` |

## 7. Conversations (Inbox)
| | |
|---|---|
| Owns | conversations/threads (`eb_conv_state`), messages (`eb_messages`), internal notes & mentions (`eb_conv_events`), assignment & history (`eb_assign_log`), presence (`eb_chat_presence`), saved replies (`eb_canned_replies`), CSAT, conversation SLA clocks |
| References | person (Customer Identity), channel identity, users/teams |
| Commands | `receive(message)`, `reply(conversation, content, idempotencyKey)` (delegates delivery to Notifications), `assign`, `handover(note)`, `snooze`, `close`, `reopen` |
| Events | `conversation.message.received/sent`, `conversation.assigned/unassigned/closed/reopened` |
| Failure | Reply delivery failure ⇒ message state `failed` with provider code; never shown as delivered unless provider says so |
| Absorbs | `Desk`, `Inbox`, `ChatAssign`, `Canned`, `Csat`, `ConvIndex`, `TeamChat` (internal chat → separate small context later) |

## 8. Channels (Connectors)
| | |
|---|---|
| Owns | connector registry & encrypted credentials (new `eb_connectors`), provider capability declarations, webhook endpoints & secrets, inbound dedupe (`eb_webhook_events`), provider health, provider API logs |
| References | tenant only |
| Commands | `connect`, `test`, `rotateCredentials`, `pause`, `send(connector, recipient, payload, idempotencyKey)`, `handleInbound(raw, headers)` |
| Events | `channel.inbound.received` (normalized), `channel.delivery.updated`, `channel.health.changed` |
| Failure | Provider error mapped to `{retryable, code, message}`; retryable ⇒ job retry with backoff; permanent ⇒ failed state |
| Absorbs | `channels/*` drivers (Bale, Telegram, Rubika, Safir, SMS×3, Email, Voice, Webchat, Instagram×4), `webhook/*`, `InstagramApichi/Roboclick/Directsaz/Auth`, Telefonchy client (`crm/api_integration`), `ApiConnectors` |

## 9. Notifications & Messaging
| | |
|---|---|
| Owns | outbound message requests, templates (versioned, channel-validated), campaigns & steps, schedules, send logs & targets, delivery attempts & states, suppression & quiet hours, in-app notifications (`eb_notifications`), website notices |
| References | person + consent (Customer Identity), connectors (Channels) |
| Commands | `send(recipient, template|content, channelPolicy, purpose, idempotencyKey)`, `scheduleCampaign`, `cancel`, `notifyUser(userId, event)` |
| Events | `notification.queued/sent/delivered/failed`, `campaign.started/finished` |
| Failure | Consent/opt-out/quiet-hours checked **before** queueing; bounded retries; dead-letter visible in panel |
| Absorbs | `Sender`, `Queue`, `SendLog`, `Templates`, `Campaigns`, `Scheduler`, `Personalize`, `SmsDlr`, `Notifier`, `Notices`, `Links` (tracking) |

## 10. Automation (Rules)
| | |
|---|---|
| Owns | rule definitions (versioned), graph flows, runs & step logs, kill switches, quotas |
| References | events from any context; commands to Work/CRM/Conversations/Notifications via their Api |
| Commands | `saveRule(version)`, `enable/disable`, `dryRun(rule, sampleEvent)`, `pauseAll` |
| Events | `automation.run.started/completed/failed` |
| Failure | Loop guard (max chain depth 5, max runs per subject per hour), per-tenant run quota, permission re-check as the rule owner at execution |
| Absorbs | `Flows` (the general engine — becomes the core), `AutoReply`, `BotMenu`, `Followups`, `IgCommentAutomation`, campaign triggers — consolidated gradually (ADR-010) |

## 11. Files & Attachments
| | |
|---|---|
| Owns | file metadata (new `eb_files`: owner context, subject ref, path, mime, size, sha256, uploaded_by), storage paths per tenant |
| Commands | `store(stream, mime, subject)`, `authorizeDownload(user, file)`, `delete` (soft, retention-driven) |
| Failure | MIME sniffed server-side; size limits per plan; never served directly from a guessable path |
| Absorbs | `media.php`, `uploads/` handling in Inbox/Instagram/Carousel/CRM |

## 12. Analytics & Reporting
| | |
|---|---|
| Owns | metric definitions (name, formula, source, timezone, inclusion rules), rollup tables, saved & scheduled reports |
| References | read-only views over other contexts |
| Rule | Every KPI documented (master prompt §14.1); event time vs processing time kept separate |
| Absorbs | `Stats`, `Analytics*` (website analytics), `ContentMetrics`, `SearchConsole`, CRM `Report` |

## 13. Audit & Compliance
| | |
|---|---|
| Owns | append-only audit log (extends `eb_audit`: actor, action, subject ref, before/after hash, ip, request_id), export logs, access reviews |
| Consumes | security-relevant events from all contexts |
| Rule | No update/delete API; retention by policy; admin search & export permission-gated |

## 14. Tenant & Subscription
| | |
|---|---|
| Owns | (control plane) tenants, domains, plans, entitlements, usage meters, subscription lifecycle, invoices; (self-hosted) licence (`modules/License.php`) |
| API | `Entitlements::allows(feature)`, `Entitlements::limit(name)`, `Usage::record(meter, n)` — only central place where plan rules live |
| Absorbs | `License.php`, `AnalyticsLicense.php` |

## 15. Platform Administration
| | |
|---|---|
| Owns | tenant provisioning, migration orchestration per tenant, support impersonation (time-limited, audited, visible banner), health dashboards |
| Rule | Cross-tenant actions only from the control plane with explicit platform-admin permission; never from inside a tenant session |
