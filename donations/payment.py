from __future__ import annotations

from dataclasses import dataclass, field
from enum import Enum
from typing import Optional


class PaymentStatus(str, Enum):
    """Lifecycle state of a donation in the Przelewy24 flow."""

    INITIATED = "initiated"
    PENDING = "pending"
    SUCCESS = "success"
    FAILED = "failed"
    CANCELLED = "cancelled"


@dataclass
class Payment:
    """Represents the minimal data needed to render a donation history item.

    The class tracks the status of a Przelewy24 transaction, which may be
    updated using synchronous transaction verification or asynchronous
    notifications.
    """

    payment_id: str
    status: PaymentStatus = PaymentStatus.INITIATED
    failure_reason: Optional[str] = None
    _status_history: list[PaymentStatus] = field(default_factory=list, init=False)

    def update_from_p24(self, payload: dict) -> PaymentStatus:
        """Update the status based on a Przelewy24 response or webhook.

        Przelewy24 returns the transaction status in the ``status`` or
        ``trn_status`` field. According to the provider documentation the
        relevant values are:

        * ``success`` – payment confirmed, funds captured.
        * ``pending`` / ``processing`` – payment is awaiting final
          confirmation and should be shown as *pending*.
        * ``error`` or any other non-success code – payment failed.
        * ``cancelled`` / ``abandoned`` – user stopped the flow.

        Any non-success value should no longer be displayed as ``initiated``
        in the donation history to avoid misleading donors.
        """

        normalized_status = self._normalize_status(payload)
        error_message = self._extract_error(payload)

        if normalized_status == PaymentStatus.SUCCESS:
            self.status = PaymentStatus.SUCCESS
            self.failure_reason = None
        elif normalized_status == PaymentStatus.PENDING:
            # Preserve a more advanced status (e.g. success), otherwise
            # surface that the payment is awaiting confirmation.
            if self.status not in {PaymentStatus.SUCCESS, PaymentStatus.CANCELLED, PaymentStatus.FAILED}:
                self.status = PaymentStatus.PENDING
        elif normalized_status == PaymentStatus.CANCELLED:
            self.status = PaymentStatus.CANCELLED
            self.failure_reason = None
        else:
            self.status = PaymentStatus.FAILED
            self.failure_reason = error_message or "Przelewy24 returned an error state."

        self._status_history.append(self.status)
        return self.status

    def _normalize_status(self, payload: dict) -> PaymentStatus:
        raw_status = str(payload.get("status") or payload.get("trn_status") or "").lower()

        if raw_status in {"success", "confirmed"}:
            return PaymentStatus.SUCCESS
        if raw_status in {"pending", "processing", "waiting_for_confirmation"}:
            return PaymentStatus.PENDING
        if raw_status in {"cancelled", "abandoned"}:
            return PaymentStatus.CANCELLED

        return PaymentStatus.FAILED if raw_status else PaymentStatus.FAILED

    def _extract_error(self, payload: dict) -> Optional[str]:
        error_code = payload.get("errorCode") or payload.get("error")
        description = payload.get("errorDescription") or payload.get("reason")

        if error_code and description:
            return f"{error_code}: {description}"
        if error_code:
            return str(error_code)
        if description:
            return str(description)
        return None

    def history_entry(self) -> str:
        """Return a concise, user-facing summary for the payment list."""

        base = f"Payment {self.payment_id} — {self.status.value}"
        if self.failure_reason and self.status == PaymentStatus.FAILED:
            return f"{base} (reason: {self.failure_reason})"
        return base

    @property
    def status_history(self) -> tuple[PaymentStatus, ...]:
        return tuple(self._status_history)
