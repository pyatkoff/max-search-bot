-- New assessments are separate immutable generations. Legacy rows keep their original JSON and times.
ALTER TABLE modeled_conversion_profile_versions
 ADD COLUMN assessment_namespace CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '0000000000000000000000000000000000000000000000000000000000000000',
 ADD COLUMN assessment_kind VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'legacy',
 DROP INDEX uq_profile_scope_cursor,
 ADD UNIQUE KEY uq_profile_scope_cursor(project_key,platform,customer_key,conversation_id,journal_cursor,assessment_namespace),
 ADD KEY ix_profile_assessment_time(project_key,observed_at_unix,id),
 ADD KEY ix_profile_assessment_kind(project_key,assessment_namespace,assessment_kind,id);
