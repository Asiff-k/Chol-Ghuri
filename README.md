# Chol Ghuri

A group travel matching and cost-sharing platform for Bangladeshi university students.
Final-year CSE project. Built with **HTML5, CSS3, vanilla JavaScript, PHP 8.2 (PDO) and MySQL/MariaDB** on XAMPP.
The only third-party library is **PHPMailer** (installed with Composer) for sending email.

---

## 1. Run it

1. Open the **XAMPP Control Panel** and start **Apache** and **MySQL**.
2. Create the database (first time, or to reset everything):
   - phpMyAdmin → **Import** → choose `database.sql` → **Go**, or
   - `C:\xampp\mysql\bin\mysql.exe -u root < C:\xampp\htdocs\chol-ghuri\database.sql`
3. Open **http://localhost/chol-ghuri/**

Importing `database.sql` deletes all Chol Ghuri data (including accounts you registered) and loads fresh demo data.

## 2. Settings: `config/.env`

All machine-specific settings and secrets live in `config/.env` (template: `config/.env.example`).
The browser can't open this file (`config/.htaccess`). Never put it in a public repository or report.

| Setting | Meaning |
|---|---|
| `APP_URL` | Full site address without a trailing slash, e.g. `http://localhost/chol-ghuri`. Every link in emails is built from it. Change it when you deploy. |
| `APP_DEBUG` | `true` shows detailed errors on screen; `false` shows a friendly page and logs details to `logs/error.log`. |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION` | Your email provider's SMTP server, e.g. `smtp.gmail.com`, `587`, `tls`. |
| `SMTP_USERNAME`, `SMTP_PASSWORD` | SMTP login. For Gmail use your address and a 16-letter **App Password** (not your normal password). |
| `MAIL_FROM`, `MAIL_FROM_NAME` | Sender address and name shown in the email. |

### Turning on email with Gmail

1. In your Google Account, turn on **2-Step Verification**.
2. Google Account → Security → **App passwords** → create one (e.g. "Chol Ghuri"). Copy the 16 letters.
3. Edit `config/.env`:
   ```
   SMTP_HOST=smtp.gmail.com
   SMTP_PORT=587
   SMTP_ENCRYPTION=tls
   SMTP_USERNAME=your.address@gmail.com
   SMTP_PASSWORD=abcdefghijklmnop
   MAIL_FROM=your.address@gmail.com
   ```
4. Register with any email address. The verification email arrives within a minute (check spam).
   The link points to `APP_URL`, so open it on the same computer while `APP_URL` is `localhost`.

Until SMTP is filled in, registration still creates the account but clearly says **the email could not be sent**. An admin can verify an account manually (Admin → Users → Verify).

## 3. Demo accounts

All demo accounts use the password **`password123`** and reserved `@example.com` addresses, so no email is ever sent to a real person.

| Email | Role | Good for |
|---|---|---|
| asif.khan@example.com | Student (LU) | Main demo: dashboard, saved search with matches, organiser of the Sajek group, receives ৳491 in the Ratargul trip |
| farhan.ahmed@example.com | Student (BUET) | Second user for joining / multi-user tests |
| nusrat.jahan@example.com | Student (NSU) | Owes money in the Ratargul trip |
| rafi.hasan@example.com | Student (LU) | Receives money in the Ratargul trip |
| anisur.rahman@example.com | Student (DU) | Owes ৳175 in the Ratargul trip |
| sadia.islam@example.com | Student (BRACU) | Organiser of a women-only group |
| tanvir.hossain@example.com | Student (SUST) | Organiser of a SUST-only group |
| tasnim.ferdous@example.com | Student (SUST) | Member of the university-only and women-only groups |
| mim.akter@example.com | Student, **not verified** | Admin can verify her manually |
| admin@cholghuri.test | Admin | Admin panel |

To test real email verification, register your own Gmail address after setting up SMTP.

## 4. What is real and what is demo

| Feature | Status |
|---|---|
| Email verification & password reset | **Real**: random one-time tokens (stored only as SHA-256 hashes), 24 h / 60 min expiry, sent by SMTP via PHPMailer. |
| University | Chosen at registration and saved. Any email domain is accepted (`REQUIRE_UNIVERSITY_EMAIL=false` in `config/config.php`). The university is shown as **self-declared** unless the email matches its domain. |
| Matching, prices, balances, settlement, ratings | **Real**: always calculated from the database. |
| Booking payment | **Demo/sandbox**: `payment-gateway.php` imitates an SSLCommerz checkout. Success / failure / pending are stored in `bookings`; no real money moves. |
| Settlement between students | **Real record keeping**: the payer marks a transfer paid, the receiver confirms. The money itself is paid outside the app. |

## 5. Main pages

| Page | File |
|---|---|
| Home | `index.php` |
| Register / Check email / Verify link / Login / Forgot / Reset / Change password / Logout | `register.php`, `verify-email.php`, `verify.php`, `login.php`, `forgot-password.php`, `reset-password.php`, `change-password.php`, `logout.php` |
| Dashboard | `dashboard.php` |
| Profile / Edit profile | `profile.php`, `edit-profile.php` |
| Package catalogue / Package details | `packages.php`, `package.php?id=` |
| Destinations | `destinations.php`, `destination.php?id=` |
| Find groups (trip intent, matching, "why no match") | `groups.php` |
| Start a group | `create-group.php` |
| Group room (join, leave, confirm, complete) | `group.php?id=` |
| Booking payment (sandbox) | `booking.php?group=`, `payment-gateway.php` |
| Trip expenses & balances | `expenses.php?group=` |
| Settle balance | `settle.php?group=` |
| Rate members | `rate.php?group=` |
| My trips | `my-trips.php` |
| Admin | `admin/index.php`, `admin/packages.php`, `admin/destinations.php`, `admin/universities.php`, `admin/tags.php` |
| JSON (live member count & price, Postman-friendly) | `api/group-status.php?id=` |

## 6. Where the logic lives

| Logic | File |
|---|---|
| Settings & `.env` loader | `config/config.php`, `config/env.php` |
| Email (PHPMailer, SMTP) | `includes/mailer.php` |
| Dynamic price: `shared ÷ members + per-person` (rounded up to whole taka) | `includes/pricing.php` |
| Groups: join / leave / confirm / complete, minimum headcount, eligibility | `includes/groups.php` |
| Matching engine (hard filters + Dates 30 / Budget 30 / Size 15 / Interests 15 / Trip & stay 10 + explanations + diagnosis) | `includes/matching.php` |
| Expenses (exact whole-taka splits), balances, greedy settlement, sandbox bookings | `includes/money.php` |
| Login, sessions, remember-me, page protection | `includes/auth.php` |

## 7. Database tables

`universities`, `users`, `student_profiles`, `tags`, `user_tags`, `destinations`, `packages`,
`package_costs` (package cost items), `travel_groups`, `group_members`, `group_tags`, `trip_intents`,
`expenses`, `expense_shares`, `settlements` (settlement transfers), `bookings` (sandbox payments), `ratings`.

## 8. Manual test checklist

1. Fill in SMTP in `config/.env` → **Register** with your Gmail → open the email → **Verify My Email** → log in.
2. Without SMTP: registration says the email could not be sent (nothing is faked). Admin → Verify works as a fallback.
3. **Log in as Asif** → Dashboard: Sajek trip, 2 matches from his saved search, ৳491 pending, real activity.
4. **Find Groups**: *Inani Beach Long Weekend 88%*, *Cox's Bazar Photo Walk 78%*. Open "Why 88%?".
5. New Search → Sajek Valley, 11–15 Dec 2026, ৳6,000 → "No compatible groups found" with reasons.
6. Join Inani Beach from the results → 2 → 3 members, ৳11,700 → ৳9,200. Leave → price goes back.
7. **Start a Trip** → live price preview → Post → you are organiser. Log in as other demo users to join.
8. Sajek group (4/6, minimum 4) → **Confirm Group** → **Pay Booking** → try failure, then success.
9. Ratargul Day Out → add ৳100 split between 3 → shares 34 + 33 + 33. Custom split that doesn't add up → error.
10. Settle: Asif **Confirm Received** (or **Not received**); payers **Mark as Paid**, receivers confirm.
11. When everyone paid and all transfers are confirmed → **Mark Trip Completed** → **Rate Your Group**.
12. Admin: edit a package's cost items and see prices change; manage destinations, universities, tags; block/unblock/verify.

## 9. Credits

Photos: Wikimedia Commons (`assets/images/CREDITS.md`). Icons: Lucide (ISC). Fonts: Plus Jakarta Sans, Noto Sans Bengali (SIL OFL). Email: PHPMailer (LGPL).
