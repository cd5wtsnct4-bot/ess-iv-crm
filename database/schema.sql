-- ESS IV Accommodation CRM — MySQL 5.7+/8.x or MariaDB 10.3+ schema
-- The web installer (install.php) runs this file automatically.
-- To install by hand: import this file in phpMyAdmin, then import rooms_seed.sql.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username        VARCHAR(60)  NOT NULL UNIQUE,
  full_name       VARCHAR(120) NOT NULL DEFAULT '',
  password_hash   VARCHAR(255) NOT NULL,
  role            ENUM('admin','staff') NOT NULL DEFAULT 'staff',
  -- Per-area access for staff (admins always have full access).
  perm_reservations ENUM('none','view','edit') NOT NULL DEFAULT 'edit',
  perm_rooms        ENUM('none','view','edit') NOT NULL DEFAULT 'none',
  perm_maintenance  ENUM('none','view','edit') NOT NULL DEFAULT 'none',
  perm_finance      ENUM('none','view','edit') NOT NULL DEFAULT 'none',  -- never automatic, not even for admins
  perm_catering     ENUM('none','view','edit') NOT NULL DEFAULT 'none',
  perm_stock        ENUM('none','view','edit') NOT NULL DEFAULT 'none',
  perm_cleaning     ENUM('none','view','edit') NOT NULL DEFAULT 'none',
  department      VARCHAR(40)  NOT NULL DEFAULT '',
  active          TINYINT(1)   NOT NULL DEFAULT 1,
  failed_logins   INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until    DATETIME     NULL,
  last_login_at   DATETIME     NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per physical room (600). Holds live condition: service status, housekeeping, out-of-service reason.
CREATE TABLE IF NOT EXISTS rooms (
  id               VARCHAR(8)   NOT NULL PRIMARY KEY,
  block            CHAR(1)      NOT NULL,
  floor            TINYINT UNSIGNED NOT NULL,
  room_type        VARCHAR(20)  NOT NULL,
  rate             DECIMAL(10,2) NOT NULL DEFAULT 0,
  service          ENUM('in','maint','oos') NOT NULL DEFAULT 'in',
  hk               ENUM('Clean','Dirty','Inspected') NOT NULL DEFAULT 'Clean',
  reason           VARCHAR(80)  NOT NULL DEFAULT '',
  reason_detail    TEXT         NULL,
  since            DATE         NULL,
  expected_return  DATE         NULL,
  example          TINYINT(1)   NOT NULL DEFAULT 0,
  updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by       INT UNSIGNED NULL,
  KEY idx_rooms_service (service),
  KEY idx_rooms_block_floor (block, floor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One profile per returning guest. Saved special requests are added to every new booking for that guest.
CREATE TABLE IF NOT EXISTS guest_profiles (
  id                 VARCHAR(40)  NOT NULL PRIMARY KEY,
  guest_name         VARCHAR(120) NOT NULL,
  id_number          VARCHAR(40)  NOT NULL DEFAULT '',
  email              VARCHAR(120) NOT NULL DEFAULT '',
  phone              VARCHAR(40)  NOT NULL DEFAULT '',
  company            VARCHAR(120) NOT NULL DEFAULT '',
  nationality        VARCHAR(60)  NOT NULL DEFAULT '',
  vehicle_reg        VARCHAR(30)  NOT NULL DEFAULT '',
  standing_requests  TEXT         NULL,       -- one request per line; copied into each new booking
  dietary            VARCHAR(160) NOT NULL DEFAULT '',
  room_preference    VARCHAR(160) NOT NULL DEFAULT '',
  notes              TEXT         NULL,
  vip                TINYINT(1)   NOT NULL DEFAULT 0,
  stays              INT UNSIGNED NOT NULL DEFAULT 0,
  last_stay          DATE         NULL,
  example            TINYINT(1)   NOT NULL DEFAULT 0,
  updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by         INT UNSIGNED NULL,
  KEY idx_gp_idnum (id_number),
  KEY idx_gp_email (email),
  KEY idx_gp_phone (phone),
  KEY idx_gp_name (guest_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bookings (
  id               VARCHAR(40)  NOT NULL PRIMARY KEY,
  ref              VARCHAR(30)  NOT NULL UNIQUE,
  guest_name       VARCHAR(120) NOT NULL,
  id_number        VARCHAR(40)  NOT NULL DEFAULT '',
  nationality      VARCHAR(60)  NOT NULL DEFAULT '',
  phone            VARCHAR(40)  NOT NULL DEFAULT '',
  email            VARCHAR(120) NOT NULL DEFAULT '',
  company          VARCHAR(120) NOT NULL DEFAULT '',
  guest_type       VARCHAR(30)  NOT NULL DEFAULT '',
  vehicle_reg      VARCHAR(30)  NOT NULL DEFAULT '',
  emergency_name   VARCHAR(120) NOT NULL DEFAULT '',
  emergency_phone  VARCHAR(40)  NOT NULL DEFAULT '',
  room_id          VARCHAR(8)   NOT NULL,
  room_type        VARCHAR(20)  NOT NULL DEFAULT '',
  check_in         DATE         NOT NULL,
  check_out        DATE         NOT NULL,
  arrival_time     TIME         NULL,
  departure_time   TIME         NULL,
  adults           TINYINT UNSIGNED NOT NULL DEFAULT 1,
  children         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  rate             DECIMAL(10,2) NOT NULL DEFAULT 0,
  total            DECIMAL(12,2) NOT NULL DEFAULT 0,
  deposit          DECIMAL(12,2) NOT NULL DEFAULT 0,
  payment          VARCHAR(30)  NOT NULL DEFAULT '',
  billing_ref      VARCHAR(60)  NOT NULL DEFAULT '',
  source           VARCHAR(30)  NOT NULL DEFAULT '',
  status           ENUM('Provisional','Confirmed','Checked-in','Checked-out','Cancelled','No-show') NOT NULL DEFAULT 'Confirmed',
  cancellation_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
  group_ref        VARCHAR(30)  NOT NULL DEFAULT '',   -- shared by all rooms of a bulk (group) booking
  guest_id         VARCHAR(40)  NOT NULL DEFAULT '',   -- guest_profiles.id
  cancelled_on     DATE         NULL,
  key_card         VARCHAR(30)  NOT NULL DEFAULT '',
  special_requests TEXT         NULL,
  notes            TEXT         NULL,
  created_on       DATE         NULL,
  checked_in_on    DATE         NULL,
  checked_out_on   DATE         NULL,
  example          TINYINT(1)   NOT NULL DEFAULT 0,
  updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by       INT UNSIGNED NULL,
  KEY idx_bookings_room_dates (room_id, check_in, check_out),
  KEY idx_bookings_checkin (check_in),
  KEY idx_bookings_checkout (check_out),
  KEY idx_bookings_status (status),
  KEY idx_bookings_group (group_ref),
  CONSTRAINT fk_bookings_room FOREIGN KEY (room_id) REFERENCES rooms(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS maintenance_tickets (
  id             VARCHAR(40)  NOT NULL PRIMARY KEY,
  ticket_no      VARCHAR(30)  NOT NULL UNIQUE,
  room_id        VARCHAR(8)   NOT NULL,
  category       VARCHAR(40)  NOT NULL DEFAULT 'Other',
  priority       ENUM('Critical','High','Medium','Low') NOT NULL DEFAULT 'Medium',
  title          VARCHAR(200) NOT NULL,
  description    TEXT         NULL,
  reported_by    VARCHAR(60)  NOT NULL DEFAULT '',
  assigned_to    VARCHAR(80)  NOT NULL DEFAULT '',
  status         ENUM('Open','Assigned','In progress','Awaiting parts','Completed','Verified') NOT NULL DEFAULT 'Open',
  reported_on    DATE         NULL,
  due_date       DATE         NULL,
  completed_on   DATE         NULL,
  est_cost       DECIMAL(12,2) NOT NULL DEFAULT 0,
  actual_cost    DECIMAL(12,2) NOT NULL DEFAULT 0,
  takes_offline  TINYINT(1)   NOT NULL DEFAULT 0,
  example        TINYINT(1)   NOT NULL DEFAULT 0,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by     INT UNSIGNED NULL,
  KEY idx_tickets_room (room_id),
  KEY idx_tickets_status (status),
  CONSTRAINT fk_tickets_room FOREIGN KEY (room_id) REFERENCES rooms(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS maintenance_schedules (
  id             VARCHAR(40)  NOT NULL PRIMARY KEY,
  task           VARCHAR(200) NOT NULL,
  scope          VARCHAR(120) NOT NULL DEFAULT '',
  frequency_days INT UNSIGNED NOT NULL DEFAULT 30,
  last_done      DATE         NULL,
  owner          VARCHAR(80)  NOT NULL DEFAULT '',
  example        TINYINT(1)   NOT NULL DEFAULT 0,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by     INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every create / change / delete, with who did it.
CREATE TABLE IF NOT EXISTS audit_log (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id    INT UNSIGNED NULL,
  username   VARCHAR(60)  NOT NULL DEFAULT '',
  entity     VARCHAR(30)  NOT NULL,
  entity_id  VARCHAR(40)  NOT NULL,
  action     VARCHAR(20)  NOT NULL,
  details    TEXT         NULL,
  KEY idx_audit_entity (entity, entity_id),
  KEY idx_audit_at (at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Operating expenses entered by finance staff, used in the Profit & Loss statement.
CREATE TABLE IF NOT EXISTS finance_expenses (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  spent_on     DATE          NOT NULL,
  category     VARCHAR(60)   NOT NULL,
  description  VARCHAR(255)  NOT NULL DEFAULT '',
  supplier     VARCHAR(120)  NOT NULL DEFAULT '',
  amount       DECIMAL(12,2) NOT NULL,
  source       VARCHAR(40)   NOT NULL DEFAULT '',   -- e.g. the purchase order that created it
  example      TINYINT(1)    NOT NULL DEFAULT 0,
  created_by   INT UNSIGNED  NULL,
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_exp_date (spent_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Key/value settings editable by administrators (e.g. alert email recipients).
CREATE TABLE IF NOT EXISTS settings (
  k  VARCHAR(60) NOT NULL PRIMARY KEY,
  v  TEXT        NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Outgoing email: every alert and notification is queued here, then sent and logged.
CREATE TABLE IF NOT EXISTS email_queue (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  kind        VARCHAR(30)  NOT NULL,
  subject     VARCHAR(255) NOT NULL,
  body_text   MEDIUMTEXT   NOT NULL,
  body_html   MEDIUMTEXT   NOT NULL,
  recipients  TEXT         NOT NULL,
  status      ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error  VARCHAR(500) NOT NULL DEFAULT '',
  sent_at     DATETIME     NULL,
  KEY idx_email_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Remembers which alerts have already been emailed, so each one is sent once.
CREATE TABLE IF NOT EXISTS alerts_sent (
  alert_key  VARCHAR(160) NOT NULL PRIMARY KEY,
  sent_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Operations module: stock & purchasing, kitchen & catering, cleaning
-- =====================================================================

CREATE TABLE IF NOT EXISTS stock_items (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku            VARCHAR(30)   NOT NULL UNIQUE,
  name           VARCHAR(120)  NOT NULL,
  category       VARCHAR(40)   NOT NULL,
  department     VARCHAR(30)   NOT NULL DEFAULT 'General',   -- Kitchen, Housekeeping, Maintenance, General
  unit           VARCHAR(20)   NOT NULL DEFAULT 'each',
  qty            DECIMAL(12,3) NOT NULL DEFAULT 0,
  reorder_level  DECIMAL(12,3) NOT NULL DEFAULT 0,
  reorder_qty    DECIMAL(12,3) NOT NULL DEFAULT 0,
  unit_cost      DECIMAL(12,2) NOT NULL DEFAULT 0,
  supplier       VARCHAR(120)  NOT NULL DEFAULT '',
  location       VARCHAR(80)   NOT NULL DEFAULT '',
  expiry_date    DATE          NULL,
  active         TINYINT(1)    NOT NULL DEFAULT 1,
  example        TINYINT(1)    NOT NULL DEFAULT 0,
  updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_stock_cat (category),
  KEY idx_stock_dept (department)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every change to a stock level. qty is signed: + received/found, − issued/wasted.
CREATE TABLE IF NOT EXISTS stock_movements (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id     INT UNSIGNED  NOT NULL,
  moved_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  type        ENUM('receive','issue','adjust','waste') NOT NULL,
  qty         DECIMAL(12,3) NOT NULL,
  qty_after   DECIMAL(12,3) NOT NULL,
  unit_cost   DECIMAL(12,2) NOT NULL DEFAULT 0,
  issued_to   VARCHAR(60)   NOT NULL DEFAULT '',
  reference   VARCHAR(60)   NOT NULL DEFAULT '',
  note        VARCHAR(255)  NOT NULL DEFAULT '',
  user_id     INT UNSIGNED  NULL,
  example     TINYINT(1)    NOT NULL DEFAULT 0,
  KEY idx_mov_item (item_id, moved_at),
  KEY idx_mov_date (moved_at),
  CONSTRAINT fk_mov_item FOREIGN KEY (item_id) REFERENCES stock_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_orders (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  po_no        VARCHAR(30)   NOT NULL UNIQUE,
  supplier     VARCHAR(120)  NOT NULL,
  status       ENUM('Draft','Ordered','Received','Cancelled') NOT NULL DEFAULT 'Draft',
  ordered_on   DATE          NULL,
  expected_on  DATE          NULL,
  received_on  DATE          NULL,
  total        DECIMAL(12,2) NOT NULL DEFAULT 0,
  notes        VARCHAR(255)  NOT NULL DEFAULT '',
  created_by   INT UNSIGNED  NULL,
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  example      TINYINT(1)    NOT NULL DEFAULT 0,
  KEY idx_po_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_order_lines (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  po_id         INT UNSIGNED  NOT NULL,
  item_id       INT UNSIGNED  NOT NULL,
  qty           DECIMAL(12,3) NOT NULL,
  unit_cost     DECIMAL(12,2) NOT NULL DEFAULT 0,
  received_qty  DECIMAL(12,3) NOT NULL DEFAULT 0,
  CONSTRAINT fk_pol_po FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_pol_item FOREIGN KEY (item_id) REFERENCES stock_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per day per meal: the menu, the planned headcount and what was actually served.
CREATE TABLE IF NOT EXISTS meal_plans (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  plan_date   DATE          NOT NULL,
  meal        ENUM('Breakfast','Lunch','Packed lunch','Dinner') NOT NULL,
  menu        TEXT          NULL,
  planned     INT UNSIGNED  NULL,          -- overrides the automatic forecast when set
  served      INT UNSIGNED  NULL,
  notes       VARCHAR(255)  NOT NULL DEFAULT '',
  example     TINYINT(1)    NOT NULL DEFAULT 0,
  updated_by  INT UNSIGNED  NULL,
  UNIQUE KEY uq_meal (plan_date, meal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Food-safety log: fridge / freezer / hot-holding / delivery temperatures.
CREATE TABLE IF NOT EXISTS kitchen_checks (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  checked_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  equipment    VARCHAR(60)   NOT NULL,
  kind         ENUM('Fridge','Freezer','Hot holding','Delivery (chilled)') NOT NULL,
  temperature  DECIMAL(5,1)  NOT NULL,
  ok           TINYINT(1)    NOT NULL,
  action_taken VARCHAR(255)  NOT NULL DEFAULT '',
  user_id      INT UNSIGNED  NULL,
  example      TINYINT(1)    NOT NULL DEFAULT 0,
  KEY idx_kc_date (checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Recurring cleaning of common areas (rooms are handled on the housekeeping board).
CREATE TABLE IF NOT EXISTS cleaning_tasks (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  area            VARCHAR(60)   NOT NULL,
  task            VARCHAR(160)  NOT NULL,
  frequency_days  INT UNSIGNED  NOT NULL DEFAULT 1,
  last_done       DATE          NULL,
  assigned_to     VARCHAR(80)   NOT NULL DEFAULT '',
  supplies        VARCHAR(255)  NOT NULL DEFAULT '',
  active          TINYINT(1)    NOT NULL DEFAULT 1,
  example         TINYINT(1)    NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cleaning_log (
  id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_id   INT UNSIGNED  NOT NULL,
  done_on   DATE          NOT NULL,
  done_by   VARCHAR(80)   NOT NULL DEFAULT '',
  notes     VARCHAR(255)  NOT NULL DEFAULT '',
  user_id   INT UNSIGNED  NULL,
  example   TINYINT(1)    NOT NULL DEFAULT 0,
  KEY idx_cl_task (task_id, done_on),
  CONSTRAINT fk_cl_task FOREIGN KEY (task_id) REFERENCES cleaning_tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
