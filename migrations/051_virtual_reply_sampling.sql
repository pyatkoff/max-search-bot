-- Private whole-dialogue sampling metadata. No source, result or quota rewrite.
CREATE TABLE IF NOT EXISTS virtual_reply_sampling_groups (
    group_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    project_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    cohort ENUM('development','reserved','contaminated') NOT NULL,
    identity_complete TINYINT UNSIGNED NOT NULL DEFAULT 0,
    exposed_at DATETIME NULL,
    last_author_claimed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (group_key),
    KEY ix_virtual_sampling_cohort (project_key,cohort,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS virtual_reply_sampling_aliases (
    project_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    identity_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    group_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (project_key,identity_key),
    KEY ix_virtual_sampling_alias_group (group_key),
    CONSTRAINT fk_virtual_sampling_alias_group FOREIGN KEY (group_key) REFERENCES virtual_reply_sampling_groups(group_key) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS virtual_reply_case_sampling (
    case_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    group_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    legacy TINYINT UNSIGNED NOT NULL DEFAULT 0,
    kind ENUM('substantive','social') NOT NULL DEFAULT 'substantive',
    identity_complete TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (case_id),
    KEY ix_virtual_sampling_case_group (group_key,kind),
    CONSTRAINT fk_virtual_sampling_case FOREIGN KEY (case_id) REFERENCES virtual_reply_cases(case_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_virtual_sampling_case_group FOREIGN KEY (group_key) REFERENCES virtual_reply_sampling_groups(group_key) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS virtual_reply_sampling_claims (
    group_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    day_utc DATE NOT NULL,
    social TINYINT UNSIGNED NOT NULL DEFAULT 0,
    claimed_at DATETIME NOT NULL,
    PRIMARY KEY (group_key,day_utc),
    KEY ix_virtual_sampling_social_day (day_utc,social),
    CONSTRAINT fk_virtual_sampling_claim_group FOREIGN KEY (group_key) REFERENCES virtual_reply_sampling_groups(group_key) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Existing material is not an unseen control, including unclaimed old cases.
INSERT IGNORE INTO virtual_reply_case_sampling (case_id,legacy,kind,identity_complete)
SELECT case_id,1,'substantive',0 FROM virtual_reply_cases;
