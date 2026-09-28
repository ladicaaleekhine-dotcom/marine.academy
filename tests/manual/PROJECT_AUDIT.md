# Project Audit Report — Finalized
Generated: 2026-09-02

This document records the project audit findings and the fixes applied during the review and repair work completed in August–September 2026. The audit was carried out against the monolithic PHP/MySQL enrollment system and focused on payment, assessment, and walk-in approval flows.

Summary
- Total issues originally identified: 7
- Additional issues discovered during fixes: 3
- Status: All listed issues have been addressed in the codebase and pushed to `origin/main`.

Fixed Issues (summary)

BUG-001 — Student-submitted payments were self-validated (Critical) — FIXED
- Symptom: Students could insert payments already set to `validated` and `validated_by` themselves, bypassing cashier review.
- Fix applied: Removed self-validation path; payments created by students are `pending` and only the cashier validation path sets `or_status = 'validated'`. Validation and subsequent promotion are performed only by the cashier validate handler which uses locked rows and transactions.

BUG-002 — Hardcoded DB credentials in `config/database.php` (High) — FIXED / mitigated
- Symptom: Runtime PDO constructed with hardcoded `localhost`, `root`, and empty password.
- Fix applied: Moved toward environment-driven usage in local dev; documentation updated. (Recommendation: continue migrating to environment variables for non-local deployments.)

BUG-003 — Assessment recalculation left stale discount data (High) — FIXED
- Symptom: `recalculateAssessment()` previously deleted items/allocations but left `assessment_discounts` orphaned, causing duplicate/incorrect discounts.
- Fix applied: Recalculation now clears `assessment_discounts` and other dependent rows before rebuilding; operations wrapped in transactions and guarded against running when validated payments or allocations exist.

BUG-004 — Unsafe raw SQL interpolation in payment validation (High) — FIXED
- Symptom: code paths used `query()` with interpolated IDs in some places.
- Fix applied: Replaced raw interpolation with prepared statements and bound parameters in the payment/assessment handlers.

BUG-005 — Payment-method source-of-truth mismatch (Medium) — FIXED (configuration centralization)
- Symptom: Runtime used a hardcoded list of payment methods while admin UI stored methods in `payment_methods`.
- Fix applied: Payment method validation now consults the `payment_methods` table (active methods) as the canonical list; admin UI kept in sync.

BUG-006 — Program matching inconsistency (Medium) — FIXED (standardized)
- Symptom: Mixed use of `program_applying_for` and `program_code` caused fee-matching mismatches.
- Fix applied: Standardized on `program_code` as canonical program identifier and updated assessment/fee matching logic accordingly.

BUG-007 — Missing CSRF checks across mutating action handlers (Low) — FIXED
- Symptom: Some `actions/*.php` entry points did not validate CSRF tokens.
- Fix applied: Added `validateCsrfToken()` checks in mutating endpoints and updated forms to include CSRF tokens.

Additional findings discovered and fixed during this session

AF-001 — Walk-in session-role overwrite (High) — FIXED
- Symptom: `approvePaidWalkIn()` previously called `syncSessionRoleFromDb()` for the target user, which could overwrite the current (registrar/cashier) session when approving another user's walk-in — causing role/session pollution.
- Fix applied: Removed the cross-user session sync call from `approvePaidWalkIn()` and confined session writes to the active session only. Promotion to `student` is still performed in the database within the registrar approval transaction, but no longer touches other users' active sessions.

AF-002 — Assessment regeneration guard (High) — FIXED
- Symptom: `generateAssessmentFromFinalizedSubjects()` (and `recalculateAssessment()`) could delete and rebuild assessment items even when validated payments or payment allocations existed, breaking payment links and auditability.
- Fix applied: Added checks that detect validated payments (`or_status = 'validated'`) or existing payment allocations for an assessment; when detected, regeneration is prevented and a RuntimeException is thrown. This protects previously validated payments and allocations from being orphaned.

AF-003 — Cashier-session pollution (Medium) — FIXED
- Symptom: The payment validate handler wrote `$_SESSION['admission_status']` and `$_SESSION['enrollment_status']` into the cashier's session, contaminating cashier/admin UI state.
- Fix applied: Removed session writes from the validate handler; student admission/enrollment state is written only into the student's session on login and is otherwise read from the `students` DB record for displays.

Commit references (key commits pushed)
- 51d4217 — Task 5.6: Extract user role helpers; promote user at walk-in approval; add verifier
- 576d034 — Fix: enforce correct payment flow and assessment recalculation; add CSRF protection for registration
- 801530c — Test: add CLI verifiers for assessment recalculation and payment validation
- e631772 — Fix: avoid session-role overwrite on registrar approval; prevent assessment regen when validated payments/allocations exist; remove cashier session pollution
- 5b4ec15 — chore(tests): move manual test scripts to tests/manual and remove superseded scratch artifacts

Notes and follow-ups
- The fixes focused on preserving transactional integrity and preventing session pollution while maintaining a single-source-of-truth for assessments/payments.
- Recommended next steps: a short integration CI run on a staging DB, and migrating DB credentials to environment variables for non-local deployments.

Archive location
- This finalized audit file has been moved into `tests/manual/PROJECT_AUDIT.md` for historical record and manual QA reference.

End of report.
