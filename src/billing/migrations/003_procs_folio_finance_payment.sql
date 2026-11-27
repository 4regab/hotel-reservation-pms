-- Billing: views and stored procedures for folios, payments, refunds, checkout sessions, exports and revenue reports.
-- The PHP code calls these with Lib\Db::call instead of holding SQL. A procedure never starts or ends a transaction:
-- the caller (Lib\Tx::run) owns it, so locks taken here (UPDLOCK, HOLDLOCK) last until the caller's transaction ends.
-- Manila day = UTC time + 8 hours, as a DATE (the hotel's calendar day).

-- ---------------------------------------------------------------------------
-- Views
-- ---------------------------------------------------------------------------

-- Every payment, with the Manila calendar day it was taken.
CREATE OR ALTER VIEW dbo.vw_Payment AS
SELECT payment_id, reservation_id, folio_id, provider, method, provider_payment_id, amount_minor, currency, status,
       created_at, reconciled_at, CAST(DATEADD(HOUR, 8, created_at) AS DATE) AS day_manila
  FROM payment;
GO

-- Every refund joined to its payment, with the Manila calendar day it was completed.
CREATE OR ALTER VIEW dbo.vw_Refund AS
SELECT r.refund_id, r.payment_id, p.reservation_id, r.amount_minor, p.currency, r.approval, r.status, r.requested_by,
       r.reason, r.created_at, r.completed_at, r.decision_note, p.amount_minor AS payment_minor,
       p.provider, p.method, p.provider_payment_id,
       CAST(DATEADD(HOUR, 8, r.completed_at) AS DATE) AS completed_day_manila
  FROM refund r
  JOIN payment p ON p.payment_id = r.payment_id;
GO

-- Charges on a bill, with the reservation the bill belongs to.
CREATE OR ALTER VIEW dbo.vw_Charge AS
SELECT c.charge_id, f.reservation_id, c.description, c.amount_minor, c.created_at
  FROM charge c
  JOIN folio f ON f.folio_id = c.folio_id;
GO

-- Accounting exports without the CSV body (the list screen does not need it).
CREATE OR ALTER VIEW dbo.vw_AccountingExportSummary AS
SELECT export_id, batch_ref, period_from, period_to, status, row_count, total_minor, requested_by, approved_by,
       downloads, created_at, checksum
  FROM accounting_export;
GO

-- Hosted checkout sessions that are still waiting for payment.
CREATE OR ALTER VIEW dbo.vw_PendingCheckoutSession AS
SELECT session_id, reservation_id, amount_minor, currency
  FROM checkout_session
 WHERE status = N'PENDING';
GO

-- ---------------------------------------------------------------------------
-- Folios and charges
-- ---------------------------------------------------------------------------

-- Bill totals for one reservation: folio fields (NULL when there is no bill), captured payments and completed refunds.
-- @lock = 1 takes an update lock on the folio row first, for callers that go on to change the bill.
CREATE OR ALTER PROCEDURE dbo.usp_folio_getSummary
  @reservation_id NCHAR(36),
  @lock           BIT = 0
AS
BEGIN
  SET NOCOUNT ON;
  DECLARE @folio_id NCHAR(36), @status NVARCHAR(6), @total BIGINT, @currency NCHAR(3);
  IF @lock = 1
    SELECT @folio_id = folio_id, @status = status, @total = total_minor, @currency = currency
      FROM folio WITH (UPDLOCK, ROWLOCK) WHERE reservation_id = @reservation_id;
  ELSE
    SELECT @folio_id = folio_id, @status = status, @total = total_minor, @currency = currency
      FROM folio WHERE reservation_id = @reservation_id;

  SELECT @folio_id AS folio_id, @status AS status, @total AS total_minor, @currency AS currency,
         COALESCE((SELECT SUM(amount_minor) FROM vw_Payment
                    WHERE reservation_id = @reservation_id AND status = N'CAPTURED'), 0) AS paid_minor,
         COALESCE((SELECT SUM(amount_minor) FROM vw_Refund
                    WHERE reservation_id = @reservation_id AND status = N'COMPLETED'), 0) AS refunded_minor;
END;
GO

-- Locks and returns the bill of a reservation (folio_id, status, currency). No row when there is no bill.
CREATE OR ALTER PROCEDURE dbo.usp_folio_getForUpdate
  @reservation_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT folio_id, status, currency
    FROM folio WITH (UPDLOCK, ROWLOCK)
   WHERE reservation_id = @reservation_id;
END;
GO

-- Returns the folio id of a reservation without locking. No row when there is no bill.
CREATE OR ALTER PROCEDURE dbo.usp_folio_getId
  @reservation_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT folio_id FROM folio WHERE reservation_id = @reservation_id;
END;
GO

-- Opens the bill with the room charge and attaches earlier payments. Does nothing when the bill already exists.
-- For OTA_PREPAID bookings it also records the partner payment (once) so the bill balances.
CREATE OR ALTER PROCEDURE dbo.usp_folio_open
  @reservation_id NCHAR(36),
  @folio_id       NCHAR(36),
  @charge_id      NCHAR(36),
  @payment_id     NCHAR(36),
  @amount_minor   BIGINT,
  @currency       NCHAR(3),
  @guarantee      NVARCHAR(20) = N'NONE'
AS
BEGIN
  SET NOCOUNT ON;
  IF EXISTS (SELECT 1 FROM folio WITH (UPDLOCK, HOLDLOCK) WHERE reservation_id = @reservation_id)
    RETURN;

  INSERT INTO folio (folio_id, reservation_id, status, total_minor, currency, created_at)
  VALUES (@folio_id, @reservation_id, N'OPEN', @amount_minor, @currency, DATETRUNC(SECOND, SYSUTCDATETIME()));

  INSERT INTO charge (charge_id, folio_id, description, amount_minor, currency, created_by, created_at)
  VALUES (@charge_id, @folio_id, N'Room charge', @amount_minor, @currency, N'system', DATETRUNC(SECOND, SYSUTCDATETIME()));

  UPDATE payment SET folio_id = @folio_id WHERE reservation_id = @reservation_id AND folio_id IS NULL;

  IF @guarantee = N'OTA_PREPAID'
  BEGIN
    DECLARE @ota_ref NVARCHAR(80) = N'ota-' + @reservation_id;
    INSERT INTO payment (payment_id, reservation_id, folio_id, provider, method, provider_payment_id, provider_event_id, amount_minor, currency, status, created_at)
    SELECT @payment_id, @reservation_id, @folio_id, N'OTA', N'PARTNER', @ota_ref, NULL, @amount_minor, @currency, N'CAPTURED', DATETRUNC(SECOND, SYSUTCDATETIME())
     WHERE NOT EXISTS (SELECT 1 FROM payment WITH (UPDLOCK, HOLDLOCK) WHERE provider_payment_id = @ota_ref);
  END;
END;
GO

-- Closes the bill of a reservation (check-out).
CREATE OR ALTER PROCEDURE dbo.usp_folio_close
  @reservation_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE folio SET status = N'CLOSED' WHERE reservation_id = @reservation_id;
END;
GO

-- Adds one charge to a bill and raises the bill total by the same amount.
CREATE OR ALTER PROCEDURE dbo.usp_folio_addCharge
  @folio_id     NCHAR(36),
  @charge_id    NCHAR(36),
  @description  NVARCHAR(120),
  @amount_minor BIGINT,
  @currency     NCHAR(3),
  @created_by   NVARCHAR(64)
AS
BEGIN
  SET NOCOUNT ON;
  INSERT INTO charge (charge_id, folio_id, description, amount_minor, currency, created_by, created_at)
  VALUES (@charge_id, @folio_id, @description, @amount_minor, @currency, @created_by, DATETRUNC(SECOND, SYSUTCDATETIME()));
  UPDATE folio SET total_minor = total_minor + @amount_minor WHERE folio_id = @folio_id;
END;
GO

-- ---------------------------------------------------------------------------
-- Payments
-- ---------------------------------------------------------------------------

-- Records an on-site payment (cash, terminal, GCash). @folio_id is NULL for a payment taken before the bill is open.
CREATE OR ALTER PROCEDURE dbo.usp_payment_insertOnsite
  @payment_id          NCHAR(36),
  @reservation_id      NCHAR(36),
  @folio_id            NCHAR(36) = NULL,
  @method              NVARCHAR(20),
  @provider_payment_id NVARCHAR(80),
  @amount_minor        BIGINT,
  @currency            NCHAR(3)
AS
BEGIN
  SET NOCOUNT ON;
  INSERT INTO payment (payment_id, reservation_id, folio_id, provider, method, provider_payment_id, provider_event_id, amount_minor, currency, status, created_at)
  VALUES (@payment_id, @reservation_id, @folio_id, N'ONSITE', @method, @provider_payment_id, NULL, @amount_minor, @currency, N'CAPTURED', DATETRUNC(SECOND, SYSUTCDATETIME()));
END;
GO

-- Records a payment reported by the online provider. A repeated event or payment id raises a duplicate-key error.
CREATE OR ALTER PROCEDURE dbo.usp_payment_insertProvider
  @payment_id          NCHAR(36),
  @reservation_id      NCHAR(36),
  @folio_id            NCHAR(36) = NULL,
  @provider            NVARCHAR(16),
  @provider_payment_id NVARCHAR(80),
  @provider_event_id   NVARCHAR(80),
  @amount_minor        BIGINT,
  @currency            NCHAR(3),
  @status              NVARCHAR(8)
AS
BEGIN
  SET NOCOUNT ON;
  INSERT INTO payment (payment_id, reservation_id, folio_id, provider, provider_payment_id, provider_event_id, amount_minor, currency, status, created_at)
  VALUES (@payment_id, @reservation_id, @folio_id, @provider, @provider_payment_id, @provider_event_id, @amount_minor, @currency, @status, DATETRUNC(SECOND, SYSUTCDATETIME()));
END;
GO

-- Returns one payment (payment_id, amount_minor, status). No row when unknown.
CREATE OR ALTER PROCEDURE dbo.usp_payment_get
  @payment_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT payment_id, amount_minor, status FROM vw_Payment WHERE payment_id = @payment_id;
END;
GO

-- Returns the id of the earliest captured payment of a reservation. No row when there is none.
CREATE OR ALTER PROCEDURE dbo.usp_payment_getFirstCaptured
  @reservation_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT TOP (1) payment_id
    FROM vw_Payment
   WHERE reservation_id = @reservation_id AND status = N'CAPTURED'
   ORDER BY created_at;
END;
GO

-- Locks the captured payments of a reservation and returns a row when one exists (guards against double payment).
CREATE OR ALTER PROCEDURE dbo.usp_payment_hasCapturedForUpdate
  @reservation_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT 1 AS found
    FROM payment WITH (UPDLOCK, HOLDLOCK)
   WHERE reservation_id = @reservation_id AND status = N'CAPTURED';
END;
GO

-- Payment list for a date range (Manila days), newest first, at most 300 rows.
-- @reconciled: '0' = captured and not yet reconciled, '1' = reconciled, NULL = any.  @status: 'REVIEW' = only payments in review, NULL = any.
CREATE OR ALTER PROCEDURE dbo.usp_payment_list
  @from_date  DATE,
  @to_date    DATE,
  @reconciled NCHAR(1)     = NULL,
  @status     NVARCHAR(8)  = NULL
AS
BEGIN
  SET NOCOUNT ON;
  SELECT TOP (300) p.payment_id AS paymentId, p.reservation_id AS reservationId, p.provider, p.method,
         p.provider_payment_id AS providerPaymentId, p.amount_minor AS amountMinor, p.currency, p.status,
         p.created_at AS createdAt, p.reconciled_at AS reconciledAt, p.day_manila AS day,
         (SELECT r.status FROM refund r WHERE r.payment_id = p.payment_id) AS refundStatus
    FROM vw_Payment p
   WHERE p.day_manila BETWEEN @from_date AND @to_date
     AND (@reconciled IS NULL
          OR (@reconciled = N'0' AND p.reconciled_at IS NULL AND p.status = N'CAPTURED')
          OR (@reconciled = N'1' AND p.reconciled_at IS NOT NULL))
     AND (@status IS NULL OR p.status = @status)
   ORDER BY p.created_at DESC;
END;
GO

-- Marks one captured payment as reconciled. Returns affected = 0 when it is unknown, not captured or already reconciled.
CREATE OR ALTER PROCEDURE dbo.usp_payment_reconcile
  @payment_id    NCHAR(36),
  @reconciled_by NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE payment
     SET reconciled_at = DATETRUNC(SECOND, SYSUTCDATETIME()), reconciled_by = @reconciled_by
   WHERE payment_id = @payment_id AND status = N'CAPTURED' AND reconciled_at IS NULL;
  SELECT @@ROWCOUNT AS affected;
END;
GO

-- Reconciles every unreconciled captured payment taken on or before a Manila day. Returns how many.
CREATE OR ALTER PROCEDURE dbo.usp_payment_reconcileUpTo
  @up_to_date    DATE,
  @reconciled_by NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE payment
     SET reconciled_at = DATETRUNC(SECOND, SYSUTCDATETIME()), reconciled_by = @reconciled_by
   WHERE status = N'CAPTURED' AND reconciled_at IS NULL
     AND CAST(DATEADD(HOUR, 8, created_at) AS DATE) <= @up_to_date;
  SELECT @@ROWCOUNT AS affected;
END;
GO

-- ---------------------------------------------------------------------------
-- Refunds
-- ---------------------------------------------------------------------------

-- Creates a refund request. A second request for the same payment raises a duplicate-key error.
CREATE OR ALTER PROCEDURE dbo.usp_refund_request
  @refund_id    NCHAR(36),
  @payment_id   NCHAR(36),
  @amount_minor BIGINT,
  @requested_by NVARCHAR(64),
  @reason       NVARCHAR(40)
AS
BEGIN
  SET NOCOUNT ON;
  INSERT INTO refund (refund_id, payment_id, amount_minor, approval, status, requested_by, reason, created_at)
  VALUES (@refund_id, @payment_id, @amount_minor, N'PENDING', N'REQUESTED', @requested_by, @reason, DATETRUNC(SECOND, SYSUTCDATETIME()));
END;
GO

-- Returns the refund id requested for a payment. No row when none.
CREATE OR ALTER PROCEDURE dbo.usp_refund_getByPayment
  @payment_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT refund_id FROM refund WHERE payment_id = @payment_id;
END;
GO

-- Locks a refund and returns it with its payment details, for approval. No row when unknown.
CREATE OR ALTER PROCEDURE dbo.usp_refund_getForApproval
  @refund_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT r.refund_id, r.amount_minor, r.status, r.requested_by, p.provider_payment_id, p.reservation_id, p.payment_id, p.currency
    FROM refund r WITH (UPDLOCK, ROWLOCK)
    JOIN payment p ON p.payment_id = r.payment_id
   WHERE r.refund_id = @refund_id;
END;
GO

-- Locks a refund and returns who requested it and its status, for a reject decision. No row when unknown.
CREATE OR ALTER PROCEDURE dbo.usp_refund_getForUpdate
  @refund_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT requested_by, status
    FROM refund WITH (UPDLOCK, ROWLOCK)
   WHERE refund_id = @refund_id;
END;
GO

-- Marks a refund as approved by a manager.
CREATE OR ALTER PROCEDURE dbo.usp_refund_approve
  @refund_id   NCHAR(36),
  @approved_by NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE refund SET approval = N'APPROVED', approved_by = @approved_by WHERE refund_id = @refund_id;
END;
GO

-- Marks a refund as completed (once only; an already completed refund is left alone).
CREATE OR ALTER PROCEDURE dbo.usp_refund_complete
  @refund_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE refund
     SET status = N'COMPLETED', completed_at = DATETRUNC(SECOND, SYSUTCDATETIME())
   WHERE refund_id = @refund_id AND status <> N'COMPLETED';
END;
GO

-- Marks a refund as rejected, with the manager's note.
CREATE OR ALTER PROCEDURE dbo.usp_refund_reject
  @refund_id   NCHAR(36),
  @approved_by NCHAR(36),
  @note        NVARCHAR(200)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE refund
     SET approval = N'REJECTED', status = N'REJECTED', approved_by = @approved_by, decision_note = @note
   WHERE refund_id = @refund_id;
END;
GO

-- Refund queue, newest first, at most 200 rows. @status NULL = all statuses.
CREATE OR ALTER PROCEDURE dbo.usp_refund_list
  @status NVARCHAR(9) = NULL
AS
BEGIN
  SET NOCOUNT ON;
  SELECT TOP (200) refund_id AS refundId, payment_id AS paymentId, reservation_id AS reservationId, amount_minor AS amountMinor,
         currency, approval, status, requested_by AS requestedBy, reason, created_at AS createdAt,
         completed_at AS completedAt, decision_note AS decisionNote, payment_minor AS paymentMinor
    FROM vw_Refund
   WHERE @status IS NULL OR status = @status
   ORDER BY created_at DESC;
END;
GO

-- ---------------------------------------------------------------------------
-- Guest receipt
-- ---------------------------------------------------------------------------

-- Everything a receipt shows, as three result sets in this order: captured payments, refunds, charges.
CREATE OR ALTER PROCEDURE dbo.usp_receipt_get
  @reservation_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT payment_id AS paymentId, provider, method, provider_payment_id AS reference, amount_minor AS amountMinor,
         currency, status, created_at AS createdAt
    FROM vw_Payment
   WHERE reservation_id = @reservation_id AND status = N'CAPTURED'
   ORDER BY created_at;

  SELECT refund_id AS refundId, amount_minor AS amountMinor, status, reason, completed_at AS completedAt
    FROM vw_Refund
   WHERE reservation_id = @reservation_id
   ORDER BY created_at;

  SELECT description, amount_minor AS amountMinor, created_at AS createdAt
    FROM vw_Charge
   WHERE reservation_id = @reservation_id
   ORDER BY created_at;
END;
GO

-- ---------------------------------------------------------------------------
-- Checkout sessions
-- ---------------------------------------------------------------------------

-- Saves a hosted checkout session we created, with the price the webhook must match.
CREATE OR ALTER PROCEDURE dbo.usp_checkout_createSession
  @session_id     NVARCHAR(80),
  @reservation_id NCHAR(36),
  @amount_minor   BIGINT,
  @currency       NCHAR(3),
  @checkout_url   NVARCHAR(400)
AS
BEGIN
  SET NOCOUNT ON;
  INSERT INTO checkout_session (session_id, reservation_id, amount_minor, currency, status, checkout_url, created_at)
  VALUES (@session_id, @reservation_id, @amount_minor, @currency, N'PENDING', @checkout_url, DATETRUNC(SECOND, SYSUTCDATETIME()));
END;
GO

-- Returns a checkout session that is still pending (reservation_id, amount_minor, currency). No row otherwise.
CREATE OR ALTER PROCEDURE dbo.usp_checkout_getPending
  @session_id NVARCHAR(80)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT reservation_id, amount_minor, currency FROM vw_PendingCheckoutSession WHERE session_id = @session_id;
END;
GO

-- Locks a checkout session (any status) and returns the price saved for it. No row when unknown.
CREATE OR ALTER PROCEDURE dbo.usp_checkout_getForUpdate
  @session_id NVARCHAR(80)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT reservation_id, amount_minor, currency
    FROM checkout_session WITH (UPDLOCK, ROWLOCK)
   WHERE session_id = @session_id;
END;
GO

-- Marks a checkout session as paid.
CREATE OR ALTER PROCEDURE dbo.usp_checkout_markPaid
  @session_id NVARCHAR(80)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE checkout_session SET status = N'PAID' WHERE session_id = @session_id;
END;
GO

-- ---------------------------------------------------------------------------
-- Accounting exports
-- ---------------------------------------------------------------------------

-- Lines for a closed-day range as two result sets: captured payments (by payment day), then completed refunds (by completion day).
CREATE OR ALTER PROCEDURE dbo.usp_export_getLines
  @from_date DATE,
  @to_date   DATE
AS
BEGIN
  SET NOCOUNT ON;
  SELECT payment_id, day_manila AS day, provider, method, provider_payment_id, amount_minor, currency, reservation_id
    FROM vw_Payment
   WHERE status = N'CAPTURED' AND day_manila BETWEEN @from_date AND @to_date
   ORDER BY created_at, payment_id;

  SELECT refund_id, completed_day_manila AS day, provider, method, provider_payment_id, amount_minor, currency, reservation_id
    FROM vw_Refund
   WHERE status = N'COMPLETED' AND completed_day_manila BETWEEN @from_date AND @to_date
   ORDER BY completed_at, refund_id;
END;
GO

-- Stores a requested export with its CSV and checksum.
CREATE OR ALTER PROCEDURE dbo.usp_export_insert
  @export_id    NCHAR(36),
  @batch_ref    NVARCHAR(24),
  @period_from  DATE,
  @period_to    DATE,
  @row_count    INT,
  @total_minor  BIGINT,
  @csv          NVARCHAR(MAX),
  @checksum     NCHAR(64),
  @requested_by NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  INSERT INTO accounting_export (export_id, batch_ref, period_from, period_to, status, row_count, total_minor, csv, checksum, requested_by, created_at)
  VALUES (@export_id, @batch_ref, @period_from, @period_to, N'REQUESTED', @row_count, @total_minor, @csv, @checksum, @requested_by, DATETRUNC(SECOND, SYSUTCDATETIME()));
END;
GO

-- The 50 newest exports, without the CSV body.
CREATE OR ALTER PROCEDURE dbo.usp_export_list
AS
BEGIN
  SET NOCOUNT ON;
  SELECT TOP (50) export_id AS exportId, batch_ref AS batchRef, period_from AS periodFrom, period_to AS periodTo, status,
         row_count AS [rowCount], total_minor AS totalMinor, requested_by AS requestedBy, approved_by AS approvedBy,
         downloads, created_at AS createdAt, checksum
    FROM vw_AccountingExportSummary
   ORDER BY created_at DESC;
END;
GO

-- Locks an export and returns who requested it, its status and batch reference. No row when unknown.
CREATE OR ALTER PROCEDURE dbo.usp_export_getForUpdate
  @export_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT requested_by, status, batch_ref
    FROM accounting_export WITH (UPDLOCK, ROWLOCK)
   WHERE export_id = @export_id;
END;
GO

-- Records a manager's decision on an export. @status is APPROVED or REJECTED.
CREATE OR ALTER PROCEDURE dbo.usp_export_decide
  @export_id   NCHAR(36),
  @status      NVARCHAR(9),
  @approved_by NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE accounting_export
     SET status = @status, approved_by = @approved_by, decided_at = DATETRUNC(SECOND, SYSUTCDATETIME())
   WHERE export_id = @export_id;
END;
GO

-- Returns an export with its CSV (csv, batch_ref, checksum, status). No row when unknown.
CREATE OR ALTER PROCEDURE dbo.usp_export_get
  @export_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT csv, batch_ref, checksum, status FROM accounting_export WHERE export_id = @export_id;
END;
GO

-- Counts one more download of an export.
CREATE OR ALTER PROCEDURE dbo.usp_export_addDownload
  @export_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE accounting_export SET downloads = downloads + 1 WHERE export_id = @export_id;
END;
GO

-- ---------------------------------------------------------------------------
-- Revenue report
-- ---------------------------------------------------------------------------

-- Captured payments per Manila day and payment method (method, or provider when there is no method).
CREATE OR ALTER PROCEDURE dbo.usp_report_paymentsByDay
  @from_date DATE,
  @to_date   DATE
AS
BEGIN
  SET NOCOUNT ON;
  SELECT day_manila AS day, COALESCE(method, provider) AS pay_method, SUM(amount_minor) AS total, COUNT(*) AS n
    FROM vw_Payment
   WHERE status = N'CAPTURED' AND day_manila BETWEEN @from_date AND @to_date
   GROUP BY day_manila, COALESCE(method, provider);
END;
GO

-- Completed refunds per Manila day (the day they were completed).
CREATE OR ALTER PROCEDURE dbo.usp_report_refundsByDay
  @from_date DATE,
  @to_date   DATE
AS
BEGIN
  SET NOCOUNT ON;
  SELECT completed_day_manila AS day, SUM(amount_minor) AS total
    FROM vw_Refund
   WHERE status = N'COMPLETED' AND completed_day_manila BETWEEN @from_date AND @to_date
   GROUP BY completed_day_manila;
END;
GO

-- Two counters for the revenue screen: refunds waiting for a decision and payments held for review.
CREATE OR ALTER PROCEDURE dbo.usp_report_openCounts
AS
BEGIN
  SET NOCOUNT ON;
  SELECT (SELECT COUNT(*) FROM refund WHERE status = N'REQUESTED') AS open_refunds,
         (SELECT COUNT(*) FROM payment WHERE status = N'REVIEW')   AS payments_in_review;
END;
GO

-- Captured payments in a Manila day range with their reconciliation time (for the on-time reconciliation rate).
CREATE OR ALTER PROCEDURE dbo.usp_report_reconciliationInput
  @from_date DATE,
  @to_date   DATE
AS
BEGIN
  SET NOCOUNT ON;
  SELECT day_manila AS day, reconciled_at
    FROM vw_Payment
   WHERE status = N'CAPTURED' AND day_manila BETWEEN @from_date AND @to_date;
END;
GO
