import unittest
from classifier import Evidence, classify

class Tests(unittest.TestCase):
    def test_formal(self): self.assertEqual(classify(Evidence())['stage'], 'D0')
    def test_interest(self): self.assertEqual(classify(Evidence(meaningful_interest=True))['stage'], 'D1')
    def test_concrete(self): self.assertEqual(classify(Evidence(meaningful_interest=True,criteria_families=2))['stage'], 'D2')
    def test_offer(self): self.assertEqual(classify(Evidence(meaningful_interest=True,concrete_offer_reaction=True,customer_continued=True))['stage'], 'D3')
    def test_booking(self): self.assertEqual(classify(Evidence(meaningful_interest=True,explicit_booking_intent=True))['stage'], 'D4')
    def test_decline(self): self.assertEqual(classify(Evidence(meaningful_interest=True,declined=True))['stage'], 'D0')
    def test_ambiguous(self): self.assertEqual(classify(Evidence(context_ambiguous=True))['stage'], 'unknown')
    def test_quote(self): self.assertEqual(classify(Evidence(meaningful_interest=True,quoted_or_conditional=True))['stage'], 'D0')
    def test_manager_only(self): self.assertEqual(classify(Evidence(concrete_offer_reaction=True))['stage'], 'D0')
    def test_no_future_leak(self): self.assertEqual(classify(Evidence(meaningful_interest=True,concrete_offer_reaction=True))['stage'], 'D1')

if __name__ == '__main__': unittest.main()
