CREATE TABLE IF NOT EXISTS flore_members (
 id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 name VARCHAR(150) NOT NULL, role VARCHAR(16) NOT NULL,
 pin_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 active TINYINT NOT NULL DEFAULT 1, created_at VARCHAR(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS flore_settings (id INT PRIMARY KEY,cafe_name VARCHAR(150) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS flore_sessions (
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 member_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 expires_at BIGINT NOT NULL, KEY session_member(member_id),KEY session_expiry(expires_at),
 CONSTRAINT flore_session_member FOREIGN KEY(member_id) REFERENCES flore_members(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS flore_login_attempts (
 `key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,`count` INT NOT NULL DEFAULT 0,resets_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS flore_routines (
 id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,title VARCHAR(150) NOT NULL,description TEXT NOT NULL,
 time VARCHAR(5) NOT NULL DEFAULT '',category VARCHAR(32) NOT NULL,priority VARCHAR(16) NOT NULL DEFAULT 'normal',
 assignee_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,weekdays INT NOT NULL,starts_on CHAR(10) NOT NULL,ends_on CHAR(10) NULL,
 active TINYINT NOT NULL DEFAULT 1,version INT NOT NULL DEFAULT 0,created_by VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at VARCHAR(32) NOT NULL,updated_at VARCHAR(32) NOT NULL,checklist_meta JSON NULL,
 CONSTRAINT flore_routine_assignee FOREIGN KEY(assignee_id) REFERENCES flore_members(id),
 CONSTRAINT flore_routine_creator FOREIGN KEY(created_by) REFERENCES flore_members(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS flore_routine_rules (
 id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,routine_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 title VARCHAR(150) NOT NULL,description TEXT NOT NULL,time VARCHAR(5) NOT NULL DEFAULT '',category VARCHAR(32) NOT NULL,priority VARCHAR(16) NOT NULL,
 assignee_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,weekdays INT NOT NULL,starts_on CHAR(10) NOT NULL,ends_on CHAR(10) NULL,
 active TINYINT NOT NULL,valid_from CHAR(10) NOT NULL,valid_until CHAR(10) NULL,
 created_by VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,created_at VARCHAR(32) NOT NULL,checklist_meta JSON NULL,
 KEY rules_routine(routine_id),
 CONSTRAINT flore_rule_routine FOREIGN KEY(routine_id) REFERENCES flore_routines(id),
 CONSTRAINT flore_rule_assignee FOREIGN KEY(assignee_id) REFERENCES flore_members(id),
 CONSTRAINT flore_rule_creator FOREIGN KEY(created_by) REFERENCES flore_members(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS flore_tasks (
 id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,title VARCHAR(150) NOT NULL,description TEXT NOT NULL,
 day CHAR(10) NOT NULL,time VARCHAR(5) NOT NULL DEFAULT '',category VARCHAR(32) NOT NULL,priority VARCHAR(16) NOT NULL DEFAULT 'normal',
 assignee_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,status VARCHAR(16) NOT NULL DEFAULT 'todo',
 completed_by VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,completed_at VARCHAR(32) NULL,note TEXT NOT NULL,
 version INT NOT NULL DEFAULT 0,created_by VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,created_at VARCHAR(32) NOT NULL,deleted TINYINT NOT NULL DEFAULT 0,
 recurrence_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,rule_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
 checklist_meta JSON NULL,values_json JSON NULL,KEY tasks_day(day),UNIQUE KEY task_recurrence_day(recurrence_id,day),
 CONSTRAINT flore_task_assignee FOREIGN KEY(assignee_id) REFERENCES flore_members(id),
 CONSTRAINT flore_task_completed FOREIGN KEY(completed_by) REFERENCES flore_members(id),
 CONSTRAINT flore_task_creator FOREIGN KEY(created_by) REFERENCES flore_members(id),
 CONSTRAINT flore_task_recurrence FOREIGN KEY(recurrence_id) REFERENCES flore_routines(id),
 CONSTRAINT flore_task_rule FOREIGN KEY(rule_id) REFERENCES flore_routine_rules(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS flore_events (
 id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,task_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
 actor_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,action VARCHAR(40) NOT NULL,title VARCHAR(150) NOT NULL,note TEXT NOT NULL,created_at VARCHAR(32) NOT NULL,
 KEY events_created(created_at),
 CONSTRAINT flore_event_task FOREIGN KEY(task_id) REFERENCES flore_tasks(id),
 CONSTRAINT flore_event_actor FOREIGN KEY(actor_id) REFERENCES flore_members(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
