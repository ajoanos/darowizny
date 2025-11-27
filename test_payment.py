import unittest

from donations import Payment, PaymentStatus


class PaymentUpdateTest(unittest.TestCase):
    def test_failed_payment_no_longer_initiated(self):
        payment = Payment(payment_id="abc")
        payment.update_from_p24({"status": "error", "errorCode": "100", "errorDescription": "Rejected"})

        self.assertEqual(PaymentStatus.FAILED, payment.status)
        self.assertEqual("100: Rejected", payment.failure_reason)
        self.assertIn(PaymentStatus.FAILED, payment.status_history)

    def test_successful_payment(self):
        payment = Payment(payment_id="abc")
        payment.update_from_p24({"status": "success"})

        self.assertEqual(PaymentStatus.SUCCESS, payment.status)
        self.assertIsNone(payment.failure_reason)

    def test_pending_payment(self):
        payment = Payment(payment_id="abc")
        payment.update_from_p24({"status": "pending"})

        self.assertEqual(PaymentStatus.PENDING, payment.status)

    def test_cancelled_payment(self):
        payment = Payment(payment_id="abc")
        payment.update_from_p24({"status": "cancelled"})

        self.assertEqual(PaymentStatus.CANCELLED, payment.status)


if __name__ == "__main__":
    unittest.main()
