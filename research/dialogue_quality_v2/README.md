# Dialogue Quality V2 — offline research only

This directory is NOT connected to production scoring, goals, Metrika, senders, or advertising. The proposed D0-D4 weights (0,1,3,5,8) are experimental relative weights, NOT rubles and NOT calibrated payment probabilities.

Run: `cd research/dialogue_quality_v2 && python -m unittest -v test_classifier.py`

## Cohort comparison protocol

Read existing derived profiles only via existing authorized read-only mechanisms; no transcripts, client IDs or other personal identifiers in exports. Use 7-day and 30-day Moscow-time windows, split MAX/Telegram. Freeze each request's early observation before examining later turns; join only on the same request anchor. Report D0-D4/unknown distribution, stage progression, elapsed time to D2-D4, coverage, sample size and confidence intervals. Do not infer missing payments as nonpayments. Do not mistake Metrika PROCESSED for tour purchase. Use holdout by time and compare against existing v1. No incomplete aggregate totals: the existing forecast summary reader caps a snapshot at 128 candidates.

## Current limitation

Classifier accepts precomputed features, but the existing production profile schema has not yet been proven to contain every feature with identical semantics. This is not a production-ready evaluator. No database reader or data access has been added: a separately reviewed authorized aggregate adapter is required before live 7/30-day results can be claimed.

## No-write safety

No API clients, SQL connections, network calls, sender imports, Metrika integration, or workflow dispatch in this package. No changes to production goals, accruals, or campaigns.
