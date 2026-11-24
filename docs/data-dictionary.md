# MemberFlow — Data dictionary

Generated from the live schema (`information_schema`) after all migrations. Engine: InnoDB, charset utf8mb4.
Money columns are `DECIMAL(10,2)`; the application handles them only as integer centavos (`App\Support\Money`).

Framework support tables (`sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `password_reset_tokens`, `notifications`, `migrations`) are standard Laravel tables and are not described here.

## `users`

Login accounts. Roles that can sign in: `admin` and `member` (a member account is linked one-to-one to its member record through `member_id`). `legacy_staff` (and the retired `staff` value) are historical only and can never sign in; the rows are kept because history refers to them.

| Column | Type | Null | Default | Key | Notes |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | PK | auto increment |
| `name` | `varchar(255)` | no | — |  |  |
| `email` | `varchar(255)` | no | — | Unique |  |
| `email_verified_at` | `timestamp` | yes | `NULL` |  |  |
| `password` | `varchar(255)` | no | — |  |  |
| `role` | `varchar(20)` | no | `staff` | Index |  |
| `member_id` | `bigint(20) unsigned` | yes | `NULL` | Unique | FK → `members.id` (on delete restrict) |
| `is_active` | `tinyint(1)` | no | `1` |  |  |
| `invited_by` | `bigint(20) unsigned` | yes | `NULL` | Index | FK → `users.id` (on delete set null) |
| `remember_token` | `varchar(100)` | yes | `NULL` |  |  |
| `created_at` | `timestamp` | yes | `NULL` |  |  |
| `updated_at` | `timestamp` | yes | `NULL` |  |  |

CHECK constraints (enforced by MariaDB):

- `users_role_valid`: `role in ('admin','staff','member','legacy_staff')`
- `users_member_link_matches_role`: `role = 'member' and member_id is not null or role <> 'member' and member_id is null`

## `user_invitations`

Member-account activation invitations (role `member`, bound to `member_id` and its email): SHA-256 token hash only, status, expiry, resend count. Old staff invitations remain as history. `invited_by` is NULL when MemberFlow issued the invitation automatically after email verification.

| Column | Type | Null | Default | Key | Notes |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | PK | auto increment |
| `email` | `varchar(255)` | no | — | Index |  |
| `name` | `varchar(255)` | no | — |  |  |
| `role` | `varchar(20)` | no | `staff` |  |  |
| `member_id` | `bigint(20) unsigned` | yes | `NULL` | Index | FK → `members.id` (on delete restrict) |
| `token_hash` | `char(64)` | no | — | Unique |  |
| `status` | `varchar(20)` | no | `pending` |  |  |
| `expires_at` | `timestamp` | no | `current_timestamp()` | Index |  |
| `invited_by` | `bigint(20) unsigned` | yes | `NULL` | Index | FK → `users.id` (on delete restrict) |
| `accepted_at` | `timestamp` | yes | `NULL` |  |  |
| `accepted_user_id` | `bigint(20) unsigned` | yes | `NULL` | Index | FK → `users.id` (on delete restrict) |
| `revoked_at` | `timestamp` | yes | `NULL` |  |  |
| `revoked_by` | `bigint(20) unsigned` | yes | `NULL` | Index | FK → `users.id` (on delete restrict) |
| `send_count` | `smallint(5) unsigned` | no | `1` |  |  |
| `last_sent_at` | `timestamp` | yes | `NULL` |  |  |
| `created_at` | `timestamp` | yes | `NULL` |  |  |
| `updated_at` | `timestamp` | yes | `NULL` |  |  |

CHECK constraints (enforced by MariaDB):

- `user_invitations_role_valid`: `role in ('staff','member')`
- `user_invitations_member_link_matches_role`: `role = 'member' and member_id is not null or role <> 'member' and member_id is null`
- `user_invitations_staff_have_inviter`: `role <> 'staff' or invited_by is not null`

## `user_account_events`

Audit trail of account changes: activation, deactivation, reactivation, sign-in email changes, role changes (R6 staff retirement and its reversal), administrators created by the CLI, and historical promotions.

| Column | Type | Null | Default | Key | Notes |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | PK | auto increment |
| `user_id` | `bigint(20) unsigned` | no | — | Index | FK → `users.id` (on delete restrict) |
| `actor_id` | `bigint(20) unsigned` | yes | `NULL` | Index | FK → `users.id` (on delete restrict) |
| `type` | `varchar(30)` | no | — |  |  |
| `old_value` | `varchar(100)` | yes | `NULL` |  |  |
| `new_value` | `varchar(100)` | yes | `NULL` |  |  |
| `note` | `text` | yes | `NULL` |  |  |
| `created_at` | `timestamp` | yes | `NULL` |  |  |
| `updated_at` | `timestamp` | yes | `NULL` |  |  |

## `membership_categories`

Member categories (Student, Regular, Senior Citizen, Corporate).

| Column | Type | Null | Default | Key | Notes |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | PK | auto increment |
| `name` | `varchar(50)` | no | — | Unique |  |
| `description` | `text` | yes | `NULL` |  |  |
| `is_active` | `tinyint(1)` | no | `1` |  |  |
| `created_at` | `timestamp` | yes | `NULL` |  |  |
| `updated_at` | `timestamp` | yes | `NULL` |  |  |

## `membership_levels`

Membership levels with fee, validity (months) and benefits.

| Column | Type | Null | Default | Key | Notes |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | PK | auto increment |
| `name` | `varchar(50)` | no | — | Unique |  |
| `fee` | `decimal(10,2)` | no | `0.00` |  |  |
| `validity_months` | `smallint(5) unsigned` | no | `12` |  |  |
| `benefits` | `text` | yes | `NULL` |  |  |
| `is_active` | `tinyint(1)` | no | `1` |  |  |
| `sort_order` | `tinyint(3) unsigned` | no | `0` |  |  |
| `created_at` | `timestamp` | yes | `NULL` |  |  |
| `updated_at` | `timestamp` | yes | `NULL` |  |  |

CHECK constraints (enforced by MariaDB):

- `chk_levels_fee`: `fee >= 0`
- `chk_levels_validity`: `validity_months >= 1`

## `members`

Member records; each belongs to exactly one category and one level.

| Column | Type | Null | Default | Key | Notes |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | PK | auto increment |
| `public_id` | `char(26)` | no | — | Unique |  |
| `membership_no` | `varchar(20)` | yes | `NULL` | Unique |  |
| `first_name` | `varchar(100)` | no | — |  |  |
| `middle_name` | `varchar(100)` | yes | `NULL` |  |  |
| `last_name` | `varchar(100)` | no | — | Index |  |
| `email` | `varchar(255)` | no | — | Unique |  |
| `phone` | `varchar(30)` | yes | `NULL` |  |  |
| `birthdate` | `date` | yes | `NULL` |  |  |
| `address` | `varchar(255)` | yes | `NULL` |  |  |
| `latitude` | `decimal(10,7)` | yes | `NULL` |  |  |
| `longitude` | `decimal(10,7)` | yes | `NULL` |  |  |
| `source` | `varchar(20)` | no | `walk_in` |  |  |
| `email_verified_at` | `timestamp` | yes | `NULL` | Index |  |
| `verification_sent_at` | `timestamp` | yes | `NULL` |  |  |
| `location_consent_at` | `timestamp` | yes | `NULL` |  |  |
| `membership_category_id` | `bigint(20) unsigned` | no | — | Index | FK → `membership_categories.id` (on delete restrict) |
| `membership_level_id` | `bigint(20) unsigned` | no | — | Index | FK → `membership_levels.id` (on delete restrict) |
| `status` | `varchar(20)` | no | `pending` | Index |  |
| `membership_start` | `date` | yes | `NULL` |  |  |
| `membership_end` | `date` | yes | `NULL` | Index |  |
| `created_by` | `bigint(20) unsigned` | yes | `NULL` | Index | FK → `users.id` (on delete restrict) |
| `created_at` | `timestamp` | yes | `NULL` |  |  |
| `updated_at` | `timestamp` | yes | `NULL` |  |  |
| `deleted_at` | `timestamp` | yes | `NULL` |  |  |

## `membership_transactions`

Applications and renewals with fee/validity snapshots, status and validity period.

| Column | Type | Null | Default | Key | Notes |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | PK | auto increment |
| `reference_no` | `varchar(30)` | yes | `NULL` | Unique |  |
| `member_id` | `bigint(20) unsigned` | no | — | Index | FK → `members.id` (on delete restrict) |
| `membership_level_id` | `bigint(20) unsigned` | no | — | Index | FK → `membership_levels.id` (on delete restrict) |
| `type` | `varchar(20)` | no | — |  |  |
| `amount` | `decimal(10,2)` | no | — |  |  |
| `validity_months` | `smallint(5) unsigned` | no | — |  |  |
| `amount_paid` | `decimal(10,2)` | no | `0.00` |  |  |
| `status` | `varchar(20)` | no | `pending` | Index |  |
| `period_start` | `date` | yes | `NULL` |  |  |
| `period_end` | `date` | yes | `NULL` |  |  |
| `approved_at` | `timestamp` | yes | `NULL` |  |  |
| `approved_by` | `bigint(20) unsigned` | yes | `NULL` | Index | FK → `users.id` (on delete restrict) |
| `cancelled_at` | `timestamp` | yes | `NULL` |  |  |
| `cancelled_by` | `bigint(20) unsigned` | yes | `NULL` | Index | FK → `users.id` (on delete restrict) |
| `cancellation_reason` | `varchar(255)` | yes | `NULL` |  |  |
| `remarks` | `text` | yes | `NULL` |  |  |
| `created_by` | `bigint(20) unsigned` | yes | `NULL` | Index | FK → `users.id` (on delete restrict) |
| `created_at` | `timestamp` | yes | `NULL` | Index |  |
| `updated_at` | `timestamp` | yes | `NULL` |  |  |

CHECK constraints (enforced by MariaDB):

- `chk_txn_amount`: `amount >= 0`
- `chk_txn_amount_paid`: `amount_paid >= 0 and amount_paid <= amount`

## `payments`

Payments against a transaction; voided (never deleted) with a reason.

| Column | Type | Null | Default | Key | Notes |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | PK | auto increment |
| `receipt_no` | `varchar(30)` | yes | `NULL` | Unique |  |
| `membership_transaction_id` | `bigint(20) unsigned` | no | — | Index | FK → `membership_transactions.id` (on delete restrict) |
| `amount` | `decimal(10,2)` | no | — |  |  |
| `method` | `varchar(20)` | no | — |  |  |
| `external_reference` | `varchar(100)` | yes | `NULL` |  |  |
| `paid_at` | `datetime` | no | — |  |  |
| `received_by` | `bigint(20) unsigned` | no | — | Index | FK → `users.id` (on delete restrict) |
| `notes` | `text` | yes | `NULL` |  |  |
| `idempotency_key` | `char(36)` | no | — | Unique |  |
| `voided_at` | `timestamp` | yes | `NULL` |  |  |
| `voided_by` | `bigint(20) unsigned` | yes | `NULL` | Index | FK → `users.id` (on delete restrict) |
| `void_reason` | `varchar(255)` | yes | `NULL` |  |  |
| `created_at` | `timestamp` | yes | `NULL` |  |  |
| `updated_at` | `timestamp` | yes | `NULL` |  |  |

CHECK constraints (enforced by MariaDB):

- `chk_payments_amount`: `amount > 0`

## `tasks`

Administrator-assigned tasks for eligible members (archive + soft delete, never hard-deleted). Historical tasks of former staff keep their assignee.

| Column | Type | Null | Default | Key | Notes |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | PK | auto increment |
| `title` | `varchar(150)` | no | — |  |  |
| `description` | `text` | yes | `NULL` |  |  |
| `priority` | `varchar(20)` | no | `medium` |  |  |
| `status` | `varchar(20)` | no | `todo` |  |  |
| `progress` | `tinyint(3) unsigned` | no | `0` |  |  |
| `due_date` | `date` | yes | `NULL` | Index |  |
| `assigned_to` | `bigint(20) unsigned` | yes | `NULL` | Index | FK → `users.id` (on delete restrict) |
| `created_by` | `bigint(20) unsigned` | no | — | Index | FK → `users.id` (on delete restrict) |
| `member_id` | `bigint(20) unsigned` | yes | `NULL` | Index | FK → `members.id` (on delete restrict) |
| `completed_at` | `timestamp` | yes | `NULL` |  |  |
| `archived_at` | `timestamp` | yes | `NULL` | Index |  |
| `archived_by` | `bigint(20) unsigned` | yes | `NULL` | Index | FK → `users.id` (on delete restrict) |
| `created_at` | `timestamp` | yes | `NULL` |  |  |
| `updated_at` | `timestamp` | yes | `NULL` |  |  |
| `deleted_at` | `timestamp` | yes | `NULL` |  |  |

CHECK constraints (enforced by MariaDB):

- `chk_tasks_progress`: `progress between 0 and 100`

## `task_assignments`

Assignment history: who held a task and when.

| Column | Type | Null | Default | Key | Notes |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | PK | auto increment |
| `task_id` | `bigint(20) unsigned` | no | — | Index | FK → `tasks.id` (on delete restrict) |
| `user_id` | `bigint(20) unsigned` | no | — | Index | FK → `users.id` (on delete restrict) |
| `assigned_by` | `bigint(20) unsigned` | no | — | Index | FK → `users.id` (on delete restrict) |
| `assigned_at` | `timestamp` | no | `current_timestamp()` |  |  |
| `unassigned_at` | `timestamp` | yes | `NULL` |  |  |
| `created_at` | `timestamp` | yes | `NULL` |  |  |
| `updated_at` | `timestamp` | yes | `NULL` |  |  |

## `task_progress_updates`

Task activity log (created, assigned, status/progress changes, notes, completion…).

| Column | Type | Null | Default | Key | Notes |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | PK | auto increment |
| `task_id` | `bigint(20) unsigned` | no | — | Index | FK → `tasks.id` (on delete restrict) |
| `user_id` | `bigint(20) unsigned` | yes | `NULL` | Index | FK → `users.id` (on delete restrict) |
| `type` | `varchar(20)` | no | — |  |  |
| `old_status` | `varchar(20)` | yes | `NULL` |  |  |
| `new_status` | `varchar(20)` | yes | `NULL` |  |  |
| `old_progress` | `tinyint(3) unsigned` | yes | `NULL` |  |  |
| `new_progress` | `tinyint(3) unsigned` | yes | `NULL` |  |  |
| `note` | `text` | yes | `NULL` |  |  |
| `created_at` | `timestamp` | yes | `NULL` |  |  |
| `updated_at` | `timestamp` | yes | `NULL` |  |  |

CHECK constraints (enforced by MariaDB):

- `chk_tpu_progress`: `(old_progress is null or old_progress <= 100) and (new_progress is null or new_progress <= 100)`

## `email_deliveries`

Honest email delivery tracking (queued/sent/logged/failed/skipped).

| Column | Type | Null | Default | Key | Notes |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | — | PK | auto increment |
| `notification` | `varchar(100)` | no | — | Index |  |
| `dedupe_key` | `varchar(150)` | yes | `NULL` | Unique |  |
| `recipient_email` | `varchar(255)` | no | — |  |  |
| `notifiable_type` | `varchar(255)` | yes | `NULL` | Index |  |
| `notifiable_id` | `bigint(20) unsigned` | yes | `NULL` |  |  |
| `related_type` | `varchar(255)` | yes | `NULL` | Index |  |
| `related_id` | `bigint(20) unsigned` | yes | `NULL` |  |  |
| `context` | `longtext` | yes | `NULL` |  |  |
| `mailer` | `varchar(20)` | no | — |  |  |
| `status` | `varchar(20)` | no | `queued` | Index |  |
| `attempts` | `tinyint(3) unsigned` | no | `0` |  |  |
| `error_message` | `text` | yes | `NULL` |  |  |
| `message_id` | `varchar(255)` | yes | `NULL` |  |  |
| `superseded_by_id` | `bigint(20) unsigned` | yes | `NULL` | Index | FK → `email_deliveries.id` (on delete restrict) |
| `queued_at` | `timestamp` | yes | `NULL` |  |  |
| `last_attempt_at` | `timestamp` | yes | `NULL` |  |  |
| `sent_at` | `timestamp` | yes | `NULL` |  |  |
| `failed_at` | `timestamp` | yes | `NULL` |  |  |
| `created_at` | `timestamp` | yes | `NULL` | Index |  |
| `updated_at` | `timestamp` | yes | `NULL` |  |  |
