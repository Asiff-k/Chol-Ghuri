-- =====================================================================
-- CHOL GHURI - Complete database schema + demo data
-- ---------------------------------------------------------------------
-- How to import (this REBUILDS the database from scratch):
--   phpMyAdmin -> select any database -> Import -> choose this file -> Go
--   or in a terminal:
--   C:\xampp\mysql\bin\mysql.exe -u root < C:\xampp\htdocs\chol-ghuri\database.sql
--
-- WARNING: all existing Chol Ghuri data is deleted and replaced with demo data.
--
-- Demo accounts (password for ALL of them: password123)
--   asif.khan@example.com        Main demo student (organiser, has expenses & settlements)
--   farhan.ahmed@example.com     Second student (use to test multi-user actions)
--   nusrat.jahan@example.com     Student (owes money in the Ratargul trip)
--   anisur.rahman@example.com    Student
--   rafi.hasan@example.com       Student (receives money in the Ratargul trip)
--   sadia.islam@example.com      Student (female-only group organiser)
--   tanvir.hossain@example.com   Student (university-only group organiser)
--   tasnim.ferdous@example.com   Student
--   mim.akter@example.com        Student, NOT verified (an admin can verify her; register your own
--                               Gmail account to test real email verification)
--   admin@cholghuri.test       Admin (opens the admin panel)
--
-- "Today" in the demo story is early October 2026.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS chol_ghuri
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE chol_ghuri;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS ratings, bookings, settlements, expense_shares, expenses,
                     trip_intents, group_tags, group_members, travel_groups,
                     package_costs, packages, destinations,
                     user_tags, tags, student_profiles, users, universities;
SET FOREIGN_KEY_CHECKS = 1;


-- =====================================================================
-- PART 1: STUDENTS
-- =====================================================================

-- Universities. email_domain is only enforced when REQUIRE_UNIVERSITY_EMAIL
-- is true in config/config.php (it is false for local/demo testing).
CREATE TABLE universities (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(150) NOT NULL,
    short_name    VARCHAR(20)  NOT NULL,
    email_domain  VARCHAR(100) NOT NULL UNIQUE,
    city          VARCHAR(60)  NULL,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- Login accounts. Tokens are stored as SHA-256 hashes only.
CREATE TABLE users (
    id                     INT AUTO_INCREMENT PRIMARY KEY,
    university_id          INT          NULL,             -- NULL for admins
    full_name              VARCHAR(100) NOT NULL,
    email                  VARCHAR(150) NOT NULL UNIQUE,
    password_hash          VARCHAR(255) NOT NULL,
    role                   ENUM('student','admin') NOT NULL DEFAULT 'student',
    email_verified_at      DATETIME NULL,                 -- NULL = not verified yet
    verify_token_hash      CHAR(64) NULL,
    verify_token_expires   DATETIME NULL,
    verify_sent_at         DATETIME NULL,
    reset_token_hash       CHAR(64) NULL,
    reset_token_expires    DATETIME NULL,
    remember_token_hash    CHAR(64) NULL,
    remember_token_expires DATETIME NULL,
    is_blocked             TINYINT(1) NOT NULL DEFAULT 0,
    last_login_at          DATETIME NULL,
    created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX (verify_token_hash),
    INDEX (reset_token_hash),
    INDEX (remember_token_hash),
    FOREIGN KEY (university_id) REFERENCES universities(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- One profile per student. Trip settings are used by matching & recommendations.
CREATE TABLE student_profiles (
    user_id             INT PRIMARY KEY,
    gender              ENUM('male','female','other') NULL,   -- used only for women-only / men-only groups
    phone               VARCHAR(20)   NULL,
    city                VARCHAR(60)   NULL,
    bio                 TEXT          NULL,
    profile_photo       VARCHAR(255)  NULL,
    budget_min          DECIMAL(10,2) NULL,
    budget_max          DECIMAL(10,2) NULL,
    group_size_min      TINYINT       NULL,
    group_size_max      TINYINT       NULL,
    trip_type_pref      ENUM('any','day_trip','overnight','eco_tour') NOT NULL DEFAULT 'any',
    accommodation_pref  ENUM('any','shared','private')               NOT NULL DEFAULT 'any',
    show_profile        TINYINT(1)    NOT NULL DEFAULT 1,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Travel interests, shared by students, groups and trip intents.
CREATE TABLE tags (
    id     INT AUTO_INCREMENT PRIMARY KEY,
    name   VARCHAR(50) NOT NULL UNIQUE,
    color  ENUM('green','blue','yellow','grey') NOT NULL DEFAULT 'green'
) ENGINE=InnoDB;

CREATE TABLE user_tags (
    user_id  INT NOT NULL,
    tag_id   INT NOT NULL,
    PRIMARY KEY (user_id, tag_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id)  REFERENCES tags(id)  ON DELETE CASCADE
) ENGINE=InnoDB;


-- =====================================================================
-- PART 2: CATALOGUE
-- =====================================================================

CREATE TABLE destinations (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(100) NOT NULL,
    region       VARCHAR(100) NULL,
    label        VARCHAR(40)  NULL,                  -- badge, e.g. 'Coastal'
    description  TEXT         NULL,
    image        VARCHAR(255) NULL,
    is_featured  TINYINT(1)   NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- A stay or tour a group can be formed around.
CREATE TABLE packages (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    destination_id      INT          NOT NULL,
    title               VARCHAR(150) NOT NULL,
    trip_type           ENUM('day_trip','overnight','eco_tour') NOT NULL,
    accommodation_type  ENUM('shared','private','none') NOT NULL DEFAULT 'shared',  -- 'none' = day trip
    accommodation_name  VARCHAR(150) NULL,
    transport_info      VARCHAR(200) NULL,
    location            VARCHAR(150) NULL,
    description         TEXT         NULL,
    duration_days       TINYINT      NOT NULL DEFAULT 1,
    min_group_size      TINYINT      NOT NULL DEFAULT 2,
    max_group_size      TINYINT      NOT NULL DEFAULT 10,   -- capacity
    amenities           VARCHAR(255) NULL,                 -- 'wifi,breakfast,ac'
    image               VARCHAR(255) NULL,
    is_active           TINYINT(1)   NOT NULL DEFAULT 1,
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX (destination_id, is_active),
    FOREIGN KEY (destination_id) REFERENCES destinations(id),
    CHECK (min_group_size >= 1 AND min_group_size <= max_group_size)
) ENGINE=InnoDB;

-- Package cost items (the "package_cost_items" of the design):
--   per_person_price(n) = SUM(shared) / n + SUM(per_person)
CREATE TABLE package_costs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    package_id  INT           NOT NULL,
    label       VARCHAR(100)  NOT NULL,
    category    ENUM('transport','accommodation','food','activities','other') NOT NULL,
    cost_type   ENUM('shared','per_person') NOT NULL,
    amount      DECIMAL(10,2) NOT NULL,

    FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE,
    CHECK (amount >= 0)
) ENGINE=InnoDB;


-- =====================================================================
-- PART 3: GROUPS & MATCHING
-- =====================================================================

-- Group lifecycle:
--   forming -> full (automatic when max reached; back to forming if someone leaves)
--   forming/full -> confirmed   (organiser, only when members >= min_members)
--   confirmed -> completed      (organiser, only when everyone paid and all debts are settled)
--   forming -> cancelled        (organiser leaves an otherwise empty group)
CREATE TABLE travel_groups (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    package_id       INT          NOT NULL,
    creator_id       INT          NOT NULL,
    title            VARCHAR(150) NOT NULL,
    description      TEXT         NULL,
    start_date       DATE         NOT NULL,
    end_date         DATE         NOT NULL,
    min_members      TINYINT      NOT NULL DEFAULT 2,     -- minimum headcount to confirm
    max_members      TINYINT      NOT NULL,               -- target / maximum size
    budget_max       DECIMAL(10,2) NULL,
    cost_sharing     ENUM('equal','custom') NOT NULL DEFAULT 'equal',
    university_only  TINYINT(1)   NOT NULL DEFAULT 0,
    gender_rule      ENUM('any','female_only','male_only') NOT NULL DEFAULT 'any',
    status           ENUM('forming','full','confirmed','completed','cancelled') NOT NULL DEFAULT 'forming',
    confirmed_at     DATETIME     NULL,
    completed_at     DATETIME     NULL,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX (status, start_date),
    FOREIGN KEY (package_id) REFERENCES packages(id),
    FOREIGN KEY (creator_id) REFERENCES users(id) ON DELETE CASCADE,
    CHECK (end_date >= start_date),
    CHECK (min_members >= 1 AND min_members <= max_members)
) ENGINE=InnoDB;

CREATE TABLE group_members (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    group_id     INT NOT NULL,
    user_id      INT NOT NULL,
    role         ENUM('organizer','member') NOT NULL DEFAULT 'member',
    status       ENUM('joined','left','removed') NOT NULL DEFAULT 'joined',
    match_score  TINYINT NULL,
    joined_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY one_membership (group_id, user_id),
    INDEX (user_id, status),
    FOREIGN KEY (group_id) REFERENCES travel_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)  REFERENCES users(id)         ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE group_tags (
    group_id  INT NOT NULL,
    tag_id    INT NOT NULL,
    PRIMARY KEY (group_id, tag_id),
    FOREIGN KEY (group_id) REFERENCES travel_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id)   REFERENCES tags(id)          ON DELETE CASCADE
) ENGINE=InnoDB;

-- A student's search ("Trip Intent"). The matching engine compares one
-- intent against every forming group. tag_ids is a small comma list, e.g. '1,4,5'.
CREATE TABLE trip_intents (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT  NOT NULL,
    destination_id      INT  NOT NULL,
    start_date          DATE NOT NULL,
    end_date            DATE NOT NULL,
    budget_max          DECIMAL(10,2) NOT NULL,
    group_size_min      TINYINT NOT NULL,
    group_size_max      TINYINT NOT NULL,
    trip_type           ENUM('any','day_trip','overnight','eco_tour') NOT NULL DEFAULT 'any',
    accommodation_pref  ENUM('any','shared','private') NOT NULL DEFAULT 'any',
    tag_ids             VARCHAR(100) NULL,
    university_only     TINYINT(1) NOT NULL DEFAULT 0,   -- only show groups from my university
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX (user_id, created_at),
    FOREIGN KEY (user_id)        REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (destination_id) REFERENCES destinations(id),
    CHECK (end_date >= start_date),
    CHECK (group_size_min <= group_size_max)
) ENGINE=InnoDB;


-- =====================================================================
-- PART 4: MONEY
-- =====================================================================

-- Expenses paid by one member for the group during the trip (whole taka).
CREATE TABLE expenses (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    group_id      INT           NOT NULL,
    paid_by       INT           NOT NULL,
    title         VARCHAR(150)  NOT NULL,
    category      ENUM('transport','accommodation','food','activities','other') NOT NULL DEFAULT 'other',
    amount        DECIMAL(10,2) NOT NULL,
    split_type    ENUM('equal','custom') NOT NULL DEFAULT 'equal',
    expense_date  DATE          NOT NULL,
    note          VARCHAR(255)  NULL,
    created_by    INT           NOT NULL,
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX (group_id),
    FOREIGN KEY (group_id)   REFERENCES travel_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (paid_by)    REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    CHECK (amount > 0)
) ENGINE=InnoDB;

-- Who owes what for each expense. The shares of an expense always add up
-- EXACTLY to its amount (the app spreads any remainder taka deterministically).
CREATE TABLE expense_shares (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    expense_id    INT           NOT NULL,
    user_id       INT           NOT NULL,
    share_amount  DECIMAL(10,2) NOT NULL,

    UNIQUE KEY one_share (expense_id, user_id),
    FOREIGN KEY (expense_id) REFERENCES expenses(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)    REFERENCES users(id),
    CHECK (share_amount >= 0)
) ENGINE=InnoDB;

-- Settlement transfers ("settlement_transfers") from the greedy algorithm.
--   pending   -> payer clicks "Mark as Paid"         -> paid
--   paid      -> receiver clicks "Confirm Received"  -> confirmed
--   paid      -> receiver clicks "Not received"      -> back to pending
-- This is NOT a gateway payment; it records money students pay each other.
CREATE TABLE settlements (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    group_id        INT           NOT NULL,
    from_user_id    INT           NOT NULL,
    to_user_id      INT           NOT NULL,
    amount          DECIMAL(10,2) NOT NULL,
    status          ENUM('pending','paid','confirmed') NOT NULL DEFAULT 'pending',
    payment_method  ENUM('bkash','nagad','card','cash') NULL,  -- how the payer says they paid
    paid_at         DATETIME      NULL,
    confirmed_at    DATETIME      NULL,
    created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX (group_id, status),
    FOREIGN KEY (group_id)     REFERENCES travel_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (from_user_id) REFERENCES users(id),
    FOREIGN KEY (to_user_id)   REFERENCES users(id),
    CHECK (amount > 0),
    CHECK (from_user_id <> to_user_id)
) ENGINE=InnoDB;

-- Booking payments ("payments"): DEMO of an SSLCommerz-style sandbox.
-- No real money moves. The amount is always recalculated on the server.
CREATE TABLE bookings (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    group_id         INT           NOT NULL,
    user_id          INT           NOT NULL,
    amount           DECIMAL(10,2) NOT NULL,
    payment_method   ENUM('bkash','nagad','card') NOT NULL,
    transaction_ref  VARCHAR(40)   NOT NULL UNIQUE,        -- e.g. 'CG-DEMO-7F3K2Q9A'
    status           ENUM('pending','paid','failed') NOT NULL DEFAULT 'pending',
    paid_at          DATETIME      NULL,
    created_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY one_booking (group_id, user_id),
    FOREIGN KEY (group_id) REFERENCES travel_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)  REFERENCES users(id)         ON DELETE CASCADE
) ENGINE=InnoDB;

-- Post-trip ratings between members of a completed group.
CREATE TABLE ratings (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    group_id       INT          NOT NULL,
    rater_id       INT          NOT NULL,
    rated_user_id  INT          NOT NULL,
    score          TINYINT      NOT NULL,
    comment        VARCHAR(255) NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY one_rating (group_id, rater_id, rated_user_id),
    INDEX (rated_user_id),
    FOREIGN KEY (group_id)      REFERENCES travel_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (rater_id)      REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (rated_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CHECK (score BETWEEN 1 AND 5),
    CHECK (rater_id <> rated_user_id)
) ENGINE=InnoDB;


-- =====================================================================
-- DEMO DATA
-- =====================================================================

INSERT INTO universities (id, name, short_name, email_domain, city) VALUES
(1, 'Leading University',                                  'LU',    'lus.ac.bd',      'Sylhet'),
(2, 'University of Dhaka',                                 'DU',    'du.ac.bd',       'Dhaka'),
(3, 'Bangladesh University of Engineering and Technology', 'BUET',  'buet.ac.bd',     'Dhaka'),
(4, 'North South University',                              'NSU',   'northsouth.edu', 'Dhaka'),
(5, 'BRAC University',                                     'BRACU', 'bracu.ac.bd',    'Dhaka'),
(6, 'Shahjalal University of Science and Technology',      'SUST',  'sust.edu',       'Sylhet'),
(7, 'Daffodil International University',                   'DIU',   'diu.edu.bd',     'Dhaka'),
(8, 'Independent University, Bangladesh',                  'IUB',   'iub.edu.bd',     'Dhaka');

-- bcrypt hash of "password123"
SET @pw = '$2y$10$dUKF6hXo5FCrv8Z6ZF0k6.XY3Q0wpV1tP/SDgA619qcTtBWHa5u0C';

INSERT INTO users (id, university_id, full_name, email, password_hash, role, email_verified_at, created_at) VALUES
(1,  NULL, 'Chol Ghuri Admin', 'admin@cholghuri.test',      @pw, 'admin',   '2026-01-01 10:00:00', '2026-01-01 10:00:00'),
(2,  1,    'Asif Khan',        'asif.khan@example.com',       @pw, 'student', '2026-01-05 09:00:00', '2026-01-05 08:55:00'),
(3,  2,    'Anisur Rahman',    'anisur.rahman@example.com',   @pw, 'student', '2026-01-06 11:00:00', '2026-01-06 10:50:00'),
(4,  3,    'Farhan Ahmed',     'farhan.ahmed@example.com',    @pw, 'student', '2026-01-08 15:00:00', '2026-01-08 14:58:00'),
(5,  4,    'Nusrat Jahan',     'nusrat.jahan@example.com',    @pw, 'student', '2026-01-10 18:00:00', '2026-01-10 17:55:00'),
(6,  1,    'Rafi Hasan',       'rafi.hasan@example.com',      @pw, 'student', '2026-02-02 12:00:00', '2026-02-02 11:59:00'),
(7,  5,    'Sadia Islam',      'sadia.islam@example.com',     @pw, 'student', '2026-03-14 20:00:00', '2026-03-14 19:58:00'),
(8,  6,    'Tanvir Hossain',   'tanvir.hossain@example.com',  @pw, 'student', '2026-04-01 09:30:00', '2026-04-01 09:28:00'),
(9,  7,    'Mim Akter',        'mim.akter@example.com',       @pw, 'student', NULL,                  '2026-10-01 16:00:00'),
(10, 6,    'Tasnim Ferdous',   'tasnim.ferdous@example.com',  @pw, 'student', '2026-05-11 13:00:00', '2026-05-11 12:58:00');

INSERT INTO student_profiles
    (user_id, gender, phone, city, bio, budget_min, budget_max, group_size_min, group_size_max, trip_type_pref, accommodation_pref)
VALUES
(2,  'male',   '01707613617', 'Sylhet', 'CSE student who enjoys exploring Bangladesh, photography, hiking, and affordable weekend trips with friends. Always looking for new adventures and good coffee spots along the way.', 2000, 5000, 4, 6, 'overnight', 'shared'),
(3,  'male',   '01811000001', 'Dhaka',  'Loves planning trips down to the last taka. Usually the one holding the group''s receipts.', 2000, 5000, 4, 6, 'overnight', 'shared'),
(4,  'male',   '01911000002', 'Dhaka',  'Civil engineering student. Hills, trekking and long bus rides with good music.', 2000, 5000, 4, 6, 'any', 'shared'),
(5,  'female', '01611000003', 'Dhaka',  'Photography and food. I will stop for every tea stall on the way.', 2000, 5000, 4, 6, 'overnight', 'any'),
(6,  'male',   '01511000004', 'Sylhet', 'First-year student, new to group travel and excited to explore.', 1000, 2000, 2, 3, 'day_trip', 'shared'),
(7,  'female', '01311000005', 'Dhaka',  'Nature lover and amateur birdwatcher.', 2000, 5000, 4, 6, 'eco_tour', 'private'),
(8,  'male',   '01411000006', 'Sylhet', 'Adventure first, comfort later. Looking for trekking partners.', 5000, 10000, 7, 12, 'overnight', 'shared'),
(9,  'female', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'any', 'any'),
(10, 'female', '01711000007', 'Sylhet', 'Statistics student at SUST. Happiest near water.', 2000, 5000, 4, 6, 'any', 'shared');

INSERT INTO tags (id, name, color) VALUES
(1, 'Beach', 'blue'), (2, 'Hiking', 'green'), (3, 'Nature', 'green'), (4, 'Photography', 'yellow'),
(5, 'Food', 'yellow'), (6, 'Cultural Sites', 'grey'), (7, 'Adventure', 'green'), (8, 'Budget Travel', 'blue'),
(9, 'Relaxed', 'grey');

INSERT INTO user_tags (user_id, tag_id) VALUES
(2,1),(2,2),(2,3),(2,4),(2,7),
(3,3),(3,5),(3,8),
(4,2),(4,3),(4,7),
(5,4),(5,5),(5,3),
(6,1),(6,8),
(7,3),(7,9),
(8,2),(8,7),(8,4),
(10,1),(10,9),(10,3);

INSERT INTO destinations (id, name, region, label, description, image, is_featured) VALUES
(1, 'Sajek Valley',          'Rangamati',   'Hill Tracks',     'A hilltop valley above the clouds, famous for sunrise views, Konglak hill and wooden cottages.', 'sajek.jpg', 1),
(2, 'Cox''s Bazar',          'Chattogram',  'Coastal',         'The world''s longest unbroken sea beach. Perfect for weekend getaways with friends.', 'coxs-bazar.jpg', 1),
(3, 'Sylhet',                'Sylhet',      'Swamp & Hills',   'Ratargul swamp forest, Jaflong''s stone river and rolling green hills.', 'sylhet.jpg', 0),
(4, 'Srimangal',             'Moulvibazar', 'Tea Gardens',     'The tea capital of Bangladesh, with Lawachara rainforest next door.', 'srimangal.jpg', 1),
(5, 'Bandarban',             'Chattogram',  'Hill Tracks',     'Nilgiri, Chimbuk and the highest peaks of Bangladesh.', 'bandarban.jpg', 1),
(6, 'Saint Martin''s Island','Cox''s Bazar','Island',          'The only coral island of Bangladesh, a ship ride from Teknaf.', 'saint-martin.jpg', 0),
(7, 'Sundarbans',            'Khulna',      'Mangrove Forest', 'The largest mangrove forest in the world, explored by boat.', 'sundarbans.jpg', 0);

INSERT INTO packages (id, destination_id, title, trip_type, accommodation_type, accommodation_name, transport_info, location, description, duration_days, min_group_size, max_group_size, amenities, image) VALUES
(1, 1, 'Sajek Hilltop Cottage Stay',    'overnight', 'shared',  'Hilltop cottage (shared rooms)',  'Shared jeep from Khagrachari (return)', 'Ruilui Para, Sajek',
    'Shared wooden cottage overlooking the valley, a reserved jeep from Khagrachari and a sunrise walk to Konglak hill. Perfect for a relaxed weekend above the clouds.', 3, 2, 8, 'wifi,breakfast', 'sajek.jpg'),
(2, 2, 'Inani Beach Retreat',           'overnight', 'shared',  'Inani beach hotel (twin rooms)',  'AC bus Dhaka - Cox''s Bazar + local tomtom', 'Inani Beach, Cox''s Bazar',
    'Beach-side hotel stay near Inani with a day trip to Himchari. Twin rooms are shared between members.', 3, 2, 10, 'wifi,breakfast,ac,beach', 'coxs-bazar.jpg'),
(3, 3, 'Ratargul & Jaflong Day Tour',   'day_trip',  'none',    NULL,                              'Microbus for the day from Sylhet city', 'Sylhet Sadar',
    'Microbus day trip with a boat ride through Ratargul swamp forest and an afternoon at Jaflong.', 1, 2, 10, NULL, 'sylhet.jpg'),
(4, 4, 'Srimangal Tea Garden Eco Tour', 'eco_tour',  'private', 'Eco resort (private rooms)',      'CNG for 3 days', 'Srimangal, Moulvibazar',
    'Eco resort stay, tea garden walks and a guided trail in Lawachara National Park.', 3, 2, 8, 'wifi,breakfast', 'srimangal.jpg'),
(5, 5, 'Nilgiri & Chimbuk Guided Tour', 'day_trip',  'none',    NULL,                              'Chander gari jeep from Bandarban town', 'Bandarban Sadar',
    'Chander gari jeep to Chimbuk and Nilgiri with a local guide. Back in town by evening.', 1, 2, 10, NULL, 'bandarban.jpg'),
(6, 6, 'Saint Martin Island Getaway',   'overnight', 'shared',  'Island resort (shared rooms)',    'Ship Teknaf - Saint Martin (return)', 'Saint Martin''s Island',
    'Ship from Teknaf, island resort stay and a visit to Chhera Dwip at low tide.', 3, 2, 10, 'breakfast,beach', 'saint-martin.jpg'),
(7, 7, 'Sundarbans Boat Expedition',    'eco_tour',  'shared',  'Shared boat cabins',              'Tourist boat from Mongla', 'Mongla, Khulna',
    'Three days on a shared boat with forest permits, an armed guide and all meals. Spot deer, crocodiles and maybe a tiger.', 3, 4, 12, 'breakfast', 'sundarbans.jpg');

INSERT INTO package_costs (package_id, label, category, cost_type, amount) VALUES
(1, 'Jeep (Khagrachari - Sajek, return)', 'transport',     'shared',     4800),
(1, 'Hilltop cottage, 2 nights',          'accommodation', 'shared',     7200),
(1, 'Meals',                              'food',          'per_person', 1200),
(1, 'Konglak hill entry & guide',         'activities',    'per_person',  300),
(2, 'Hotel rooms, 2 nights',              'accommodation', 'shared',    12000),
(2, 'Local CNG / tomtom',                 'transport',     'shared',     3000),
(2, 'Bus Dhaka - Cox''s Bazar (return)',  'transport',     'per_person', 2400),
(2, 'Meals',                              'food',          'per_person', 1800),
(3, 'Microbus for the day',               'transport',     'shared',     5000),
(3, 'Boat at Ratargul',                   'activities',    'shared',     1200),
(3, 'Lunch',                              'food',          'per_person',  400),
(4, 'Eco resort, 2 nights',               'accommodation', 'shared',     8000),
(4, 'CNG for 3 days',                     'transport',     'shared',     2000),
(4, 'Meals',                              'food',          'per_person', 1000),
(4, 'Lawachara entry',                    'activities',    'per_person',  100),
(5, 'Chander gari jeep',                  'transport',     'shared',     6000),
(5, 'Local guide',                        'activities',    'shared',     1500),
(5, 'Lunch',                              'food',          'per_person',  350),
(6, 'Island resort, 2 nights',            'accommodation', 'shared',    10000),
(6, 'Ship Teknaf - Saint Martin (return)','transport',     'per_person', 2500),
(6, 'Meals',                              'food',          'per_person', 1500),
(7, 'Shared boat, 3 days',                'transport',     'shared',    30000),
(7, 'Forest permit & guide',              'activities',    'shared',     4000),
(7, 'Meals on board',                     'food',          'per_person', 2500);

-- ---------------------------------------------------------------------
-- Groups
--   1  Sajek Valley Weekend Escape  forming, 4/6, minimum reached (Asif organises)
--   2  Srimangal Tea Weekend        completed in August (expenses settled, ratings)
--   3  Cox's Bazar Group Tour       completed in January
--   4  Nilgiri Day Trek             forming, 2/6
--   5  Sajek Photography Weekend    forming, 2/5
--   6  Sajek Adventure Crew         forming, SUST students only
--   7  Ratargul Day Out             confirmed, trip done, settlements in progress
--   8  Inani Beach Long Weekend     forming (matches Asif's saved search)
--   9  Cox's Bazar Photo Walk       forming (matches Asif's saved search)
--  10  Cox's Sea Breeze Trip        forming (dates do not overlap Asif's search)
--  11  Saint Martin Coral Escape    forming
--  12  Girls' Srimangal Tea Escape  forming, women only
--  13  Sundarbans Boat Expedition   forming, 3/10, minimum (6) not reached
-- ---------------------------------------------------------------------
INSERT INTO travel_groups (id, package_id, creator_id, title, description, start_date, end_date, min_members, max_members, budget_max, cost_sharing, university_only, gender_rule, status, confirmed_at, completed_at, created_at) VALUES
(1,  1, 2, 'Sajek Valley Weekend Escape', 'Relaxed weekend in the clouds. Sunrise at Konglak, lots of photos, early nights. Looking for friendly, punctual people.', '2026-12-12', '2026-12-14', 4, 6,  5000,  'equal', 0, 'any',         'forming',   NULL, NULL, '2026-09-20 10:00:00'),
(2,  4, 2, 'Srimangal Tea Weekend',       'Tea gardens, Lawachara trail and seven-layer tea.',                                                                         '2026-08-14', '2026-08-16', 3, 4,  4500,  'equal', 0, 'any',         'completed', '2026-07-20 10:00:00', '2026-08-20 10:00:00', '2026-07-01 10:00:00'),
(3,  2, 3, 'Cox''s Bazar Group Tour',     'Beach, seafood and sunsets at Inani.',                                                                                      '2026-01-22', '2026-01-24', 3, 4,  10000, 'equal', 0, 'any',         'completed', '2026-01-05 10:00:00', '2026-01-28 10:00:00', '2025-12-10 10:00:00'),
(4,  5, 5, 'Nilgiri Day Trek',            'Day trip to Nilgiri and Chimbuk. Easy pace, great views.',                                                                  '2026-12-05', '2026-12-05', 3, 6,  3000,  'equal', 0, 'any',         'forming',   NULL, NULL, '2026-09-25 10:00:00'),
(5,  1, 3, 'Sajek Photography Weekend',   'Sunrise and sunset shoots, bring your camera.',                                                                             '2026-12-12', '2026-12-14', 3, 5,  6500,  'equal', 0, 'any',         'forming',   NULL, NULL, '2026-09-28 10:00:00'),
(6,  1, 8, 'Sajek Adventure Crew',        'Trekking-heavy weekend, Konglak and beyond. SUST students only.',                                                          '2026-12-13', '2026-12-15', 4, 6,  6000,  'equal', 1, 'any',         'forming',   NULL, NULL, '2026-09-30 10:00:00'),
(7,  3, 2, 'Ratargul Day Out',            'Boat through the swamp forest, lunch at Jaflong, back by night.',                                                          '2026-10-03', '2026-10-03', 3, 4,  2500,  'equal', 0, 'any',         'confirmed', '2026-09-26 10:00:00', NULL, '2026-09-15 10:00:00'),
(8,  2, 4, 'Inani Beach Long Weekend',    'Beach, sunsets and seafood. Easy-going group, early risers welcome.',                                                       '2026-11-20', '2026-11-22', 3, 6,  10000, 'equal', 0, 'any',         'forming',   NULL, NULL, '2026-09-29 10:00:00'),
(9,  2, 8, 'Cox''s Bazar Photo Walk',     'Golden-hour photography along Marine Drive and Himchari.',                                                                 '2026-11-21', '2026-11-23', 3, 4,  10000, 'equal', 0, 'any',         'forming',   NULL, NULL, '2026-10-01 10:00:00'),
(10, 2, 5, 'Cox''s Sea Breeze Trip',      'End-of-semester beach break.',                                                                                              '2026-11-28', '2026-11-30', 3, 5,  9000,  'equal', 0, 'any',         'forming',   NULL, NULL, '2026-10-02 10:00:00'),
(11, 6, 3, 'Saint Martin Coral Escape',   'Ship from Teknaf, coral beaches and Chhera Dwip.',                                                                         '2026-11-20', '2026-11-22', 3, 6,  8000,  'equal', 0, 'any',         'forming',   NULL, NULL, '2026-10-02 12:00:00'),
(12, 4, 7, 'Girls'' Srimangal Tea Escape','A calm, women-only weekend in the tea gardens.',                                                                             '2026-12-18', '2026-12-20', 3, 5,  5000,  'equal', 0, 'female_only', 'forming',   NULL, NULL, '2026-10-03 10:00:00'),
(13, 7, 8, 'Sundarbans Boat Expedition',  'Three days on the river. Needs at least 6 people to make the boat affordable.',                                             '2027-01-08', '2027-01-10', 6, 10, 9000,  'equal', 0, 'any',         'forming',   NULL, NULL, '2026-10-04 10:00:00');

INSERT INTO group_members (group_id, user_id, role, match_score, joined_at) VALUES
(1, 2, 'organizer', NULL, '2026-09-20 10:00:00'), (1, 4, 'member', 91, '2026-09-21 12:00:00'),
(1, 5, 'member', 84, '2026-09-22 18:30:00'),      (1, 6, 'member', 78, '2026-09-24 09:15:00'),
(2, 2, 'organizer', NULL, '2026-07-01 10:00:00'), (2, 3, 'member', 88, '2026-07-02 10:00:00'),
(2, 4, 'member', 90, '2026-07-03 10:00:00'),      (2, 5, 'member', 82, '2026-07-04 10:00:00'),
(3, 3, 'organizer', NULL, '2025-12-10 10:00:00'), (3, 2, 'member', 86, '2025-12-11 10:00:00'),
(3, 5, 'member', 80, '2025-12-12 10:00:00'),
(4, 5, 'organizer', NULL, '2026-09-25 10:00:00'), (4, 3, 'member', 83, '2026-09-26 10:00:00'),
(5, 3, 'organizer', NULL, '2026-09-28 10:00:00'), (5, 7, 'member', 79, '2026-09-29 10:00:00'),
(6, 8, 'organizer', NULL, '2026-09-30 10:00:00'), (6, 10, 'member', 88, '2026-10-01 09:00:00'),
(7, 2, 'organizer', NULL, '2026-09-15 10:00:00'), (7, 3, 'member', 81, '2026-09-16 10:00:00'),
(7, 5, 'member', 85, '2026-09-17 10:00:00'),      (7, 6, 'member', 90, '2026-09-18 10:00:00'),
(8, 4, 'organizer', NULL, '2026-09-29 10:00:00'), (8, 7, 'member', 77, '2026-09-30 10:00:00'),
(9, 8, 'organizer', NULL, '2026-10-01 10:00:00'), (9, 6, 'member', 74, '2026-10-02 10:00:00'),
(10, 5, 'organizer', NULL, '2026-10-02 10:00:00'),(10, 3, 'member', 80, '2026-10-02 15:00:00'),
(11, 3, 'organizer', NULL, '2026-10-02 12:00:00'),(11, 10, 'member', 76, '2026-10-03 09:00:00'),
(12, 7, 'organizer', NULL, '2026-10-03 10:00:00'),(12, 10, 'member', 92, '2026-10-03 18:00:00'),
(12, 5, 'member', 87, '2026-10-04 08:00:00'),
(13, 8, 'organizer', NULL, '2026-10-04 10:00:00'),(13, 4, 'member', 81, '2026-10-04 12:00:00'),
(13, 6, 'member', 70, '2026-10-04 15:00:00');

INSERT INTO group_tags (group_id, tag_id) VALUES
(1,3),(1,2),(1,4), (2,3),(2,5),(2,9), (3,1),(3,5), (4,2),(4,3),(4,8),
(5,4),(5,3),(5,9), (6,2),(6,7),(6,4), (7,3),(7,5), (8,1),(8,5),(8,9),
(9,4),(9,1), (10,1),(10,9), (11,1),(11,3),(11,7), (12,3),(12,9),(12,5), (13,3),(13,7),(13,4);

-- Asif's saved search (Cox's Bazar, 19-22 Nov, up to ৳10,000, 4-6 people)
INSERT INTO trip_intents (user_id, destination_id, start_date, end_date, budget_max, group_size_min, group_size_max, trip_type, accommodation_pref, tag_ids, university_only, created_at) VALUES
(2, 2, '2026-11-19', '2026-11-22', 10000, 4, 6, 'overnight', 'shared', '1,4,5', 0, '2026-10-04 20:00:00');

-- Booking payments (demo) for the confirmed/completed groups.
-- Amount = per-person price at the final member count:
--   group 2: 10,000/4 + 1,100 = 3,600    group 3: 15,000/3 + 4,200 = 9,200
--   group 7:  6,200/4 +   400 = 1,950
INSERT INTO bookings (group_id, user_id, amount, payment_method, transaction_ref, status, paid_at) VALUES
(2, 2, 3600, 'bkash', 'CG-DEMO-SRM00002', 'paid', '2026-07-21 10:00:00'),
(2, 3, 3600, 'nagad', 'CG-DEMO-SRM00003', 'paid', '2026-07-21 11:00:00'),
(2, 4, 3600, 'card',  'CG-DEMO-SRM00004', 'paid', '2026-07-22 09:00:00'),
(2, 5, 3600, 'bkash', 'CG-DEMO-SRM00005', 'paid', '2026-07-22 12:00:00'),
(3, 3, 9200, 'bkash', 'CG-DEMO-COX00003', 'paid', '2026-01-06 10:00:00'),
(3, 2, 9200, 'card',  'CG-DEMO-COX00002', 'paid', '2026-01-06 11:00:00'),
(3, 5, 9200, 'nagad', 'CG-DEMO-COX00005', 'paid', '2026-01-07 10:00:00'),
(7, 2, 1950, 'bkash', 'CG-DEMO-RTG00002', 'paid', '2026-09-27 10:00:00'),
(7, 3, 1950, 'nagad', 'CG-DEMO-RTG00003', 'paid', '2026-09-27 11:00:00'),
(7, 5, 1950, 'card',  'CG-DEMO-RTG00005', 'paid', '2026-09-27 12:00:00'),
(7, 6, 1950, 'bkash', 'CG-DEMO-RTG00006', 'paid', '2026-09-28 09:00:00');

-- ---------------------------------------------------------------------
-- Expenses
-- Group 2 (completed): total 14,400, 4 members, 3,600 each.
--   Asif paid 600 (-3,000)  Anisur 5,400 (+1,800)  Farhan 7,200 (+3,600)  Nusrat 1,200 (-2,400)
-- Group 7 (confirmed): total 2,800.
--   'Snacks & tea' ৳100 is split between 3 people as 34 + 33 + 33 (remainder taka rule).
--   Paid:  Asif 1,200  Anisur 500  Nusrat 100  Rafi 1,000
--   Owes:  Asif 709    Anisur 675  Nusrat 708  Rafi 708
--   Net:   Asif +491   Anisur -175 Nusrat -608 Rafi +292   (sum = 0)
-- ---------------------------------------------------------------------
INSERT INTO expenses (id, group_id, paid_by, title, category, amount, split_type, expense_date, note, created_by, created_at) VALUES
(1, 2, 3, 'Microbus Sylhet - Srimangal', 'transport',     4800, 'equal', '2026-08-14', NULL, 3, '2026-08-14 11:00:00'),
(2, 2, 4, 'Extra cottage night',         'accommodation', 7200, 'equal', '2026-08-14', 'Paid at the resort reception', 4, '2026-08-14 21:00:00'),
(3, 2, 5, 'Food & Dinner',               'food',          1200, 'equal', '2026-08-14', NULL, 5, '2026-08-14 22:30:00'),
(4, 2, 2, 'Local transport',             'transport',      600, 'equal', '2026-08-15', NULL, 2, '2026-08-15 18:00:00'),
(5, 2, 3, 'Activities',                  'activities',     600, 'equal', '2026-08-15', 'Seven-layer tea & guide tip', 3, '2026-08-15 19:00:00'),
(6, 7, 2, 'Boat at Ratargul',            'activities',    1200, 'equal', '2026-10-03', NULL, 2, '2026-10-03 10:30:00'),
(7, 7, 6, 'Lunch at Jaflong',            'food',          1000, 'equal', '2026-10-03', NULL, 6, '2026-10-03 14:00:00'),
(8, 7, 5, 'Snacks & tea',                'food',           100, 'equal', '2026-10-03', 'Anisur skipped tea', 5, '2026-10-03 16:45:00'),
(9, 7, 3, 'CNG to Jaflong zero point',   'transport',      500, 'equal', '2026-10-03', NULL, 3, '2026-10-03 19:30:00');

INSERT INTO expense_shares (expense_id, user_id, share_amount) VALUES
(1,2,1200),(1,3,1200),(1,4,1200),(1,5,1200),
(2,2,1800),(2,3,1800),(2,4,1800),(2,5,1800),
(3,2, 300),(3,3, 300),(3,4, 300),(3,5, 300),
(4,2, 150),(4,3, 150),(4,4, 150),(4,5, 150),
(5,2, 150),(5,3, 150),(5,4, 150),(5,5, 150),
(6,2, 300),(6,3, 300),(6,5, 300),(6,6, 300),
(7,2, 250),(7,3, 250),(7,5, 250),(7,6, 250),
(8,2,  34),(8,5,  33),(8,6,  33),
(9,2, 125),(9,3, 125),(9,5, 125),(9,6, 125);

-- Settlement transfers (exactly what the greedy algorithm produces):
--   group 2 (all confirmed): Asif -> Farhan 3,000; Nusrat -> Anisur 1,800; Nusrat -> Farhan 600
--   group 7: Nusrat -> Asif 491 (marked paid, waiting for Asif to confirm);
--            Anisur -> Rafi 175 and Nusrat -> Rafi 117 (pending)
INSERT INTO settlements (group_id, from_user_id, to_user_id, amount, status, payment_method, paid_at, confirmed_at) VALUES
(2, 2, 4, 3000, 'confirmed', 'bkash', '2026-08-17 10:00:00', '2026-08-17 12:00:00'),
(2, 5, 3, 1800, 'confirmed', 'nagad', '2026-08-17 11:00:00', '2026-08-17 13:00:00'),
(2, 5, 4,  600, 'confirmed', 'bkash', '2026-08-17 11:05:00', '2026-08-17 13:05:00'),
(7, 5, 2,  491, 'paid',      'bkash', '2026-10-04 19:00:00', NULL),
(7, 3, 6,  175, 'pending',   NULL, NULL, NULL),
(7, 5, 6,  117, 'pending',   NULL, NULL, NULL);

INSERT INTO ratings (group_id, rater_id, rated_user_id, score, comment, created_at) VALUES
(2, 4, 2, 5, 'Great travel partner. Very organized and friendly.',    '2026-08-18 10:00:00'),
(2, 5, 2, 5, 'Good communication and always paid his share on time.', '2026-08-18 11:00:00'),
(2, 3, 2, 4, 'Well planned trip, would join again.',                  '2026-08-19 09:00:00'),
(2, 2, 4, 5, 'Handled the resort booking perfectly.',                 '2026-08-18 12:00:00'),
(2, 2, 3, 5, 'Kept every receipt. Made splitting easy.',              '2026-08-18 12:05:00'),
(2, 2, 5, 4, 'Fun to travel with, great photos.',                     '2026-08-18 12:10:00'),
(2, 4, 5, 5, 'Always on time.',                                       '2026-08-18 14:00:00'),
(3, 3, 2, 5, 'Calm and helpful the whole trip.',                      '2026-01-26 10:00:00'),
(3, 5, 2, 5, NULL,                                                    '2026-01-26 11:00:00'),
(3, 2, 3, 5, 'Best organiser I have travelled with.',                 '2026-01-26 12:00:00');
