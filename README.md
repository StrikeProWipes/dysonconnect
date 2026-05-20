# DysonConnect – Online Bus Booking System

**Unit:** DWIN309 – Developing Web Information Systems  
**Assessment:** Assessment 4 – Group Project  
**Institution:** Kent Institute Australia

---

## Group Members

| Student ID | Name            | Part Implemented                      |
|------------|-----------------|---------------------------------------|
| K241034    | Bibek Subedi    | Admin Panel & Database Design         |
| K240381    | Wasik Gaus      | Bus Search & Seat Selection           |
| K241054    | Santosh Silwal  | Booking Flow & Payment                |
| K231952    | Sushil Bhusal   | User Authentication & Profile         |

---

## About the Project

DysonConnect is a prototype online bus booking system built for the Dyson Group, a fictional family-run bus operator that serves intercity routes across Victoria and New South Wales.

The system lets customers search for available buses, choose their seat, enter passenger details, pay, and get a printable e-ticket — all from a browser. An admin panel lets the operator manage buses, routes, schedules, drivers, bookings, and reports.

Built with plain PHP and MySQL — no frameworks used.

---

## Technologies

- PHP (no frameworks — plain PHP only)
- MySQL / MariaDB (via phpMyAdmin)
- HTML5 / CSS3 (custom stylesheet, no CSS frameworks)
- JavaScript (vanilla, no libraries)
- XAMPP for local development

---

## Setup Instructions (XAMPP / phpMyAdmin)

### Step 1 — Copy the project folder

Place the entire project folder inside your XAMPP `htdocs` directory:

```
Windows:   C:\xampp\htdocs\dysonconnect_v3\
macOS:     /Applications/XAMPP/htdocs/dysonconnect_v3/
```

### Step 2 — Start XAMPP

Open the XAMPP Control Panel and start both:
- **Apache**
- **MySQL**

### Step 3 — Import the database

1. Open your browser and go to: `http://localhost/phpmyadmin`
2. Click **Import** in the top navigation bar
3. Click **Choose File** and select the file: `dysonconnect_v3.sql`
4. Click **Go**

The SQL file will automatically:
- Create the database: `dysonconnect`
- Create all 10 tables
- Create 2 database views
- Insert sample data (users, routes, buses, schedules, seats, bookings, payments, notifications)

> **Important:** Only import `dysonconnect_v3.sql`. This is the final version.

### Step 4 — Open the website

Go to: `http://localhost/dysonconnect_v3/`

---

## Database Details

| Setting        | Value            |
|----------------|------------------|
| Database name  | `dysonconnect`   |
| Host           | `localhost`      |
| Username       | `root`           |
| Password       | *(empty)*        |
| SQL file       | `dysonconnect_v3.sql` |

---

## Login Credentials

### Admin Account

| Field    | Value                                    |
|----------|------------------------------------------|
| Email    | bibek.subedi@student.kent.edu.au         |
| Password | Bibek@Admin1                             |

Admin panel URL: `http://localhost/dysonconnect_v3/admin/dashboard.php`

### Customer / Demo Accounts

| Name           | Email                                  | Password       |
|----------------|----------------------------------------|----------------|
| Wasik Gaus     | wasik.gaus@student.kent.edu.au         | Wasik@Pass1    |
| Santosh Silwal | santosh.silwal@student.kent.edu.au     | Santosh@Pass1  |
| Sushil Bhusal  | sushil.bhusal@student.kent.edu.au      | Sushil@Pass1   |

Extra demo customers (password: `Pass@1234`):

| Name                | Email                           |
|---------------------|---------------------------------|
| Priya Sharma        | priya.sharma@email.com.au       |
| James Tran          | james.tran@email.com.au         |
| Anika Patel         | anika.patel@email.com.au        |
| Mohammed Al-Rashid  | mohammed.alrashid@email.com.au  |
| Sophie Nguyen       | sophie.nguyen@email.com.au      |
| David Kowalski      | david.kowalski@email.com.au     |

---

## Main Features

### Customer Side
- Register and log in (bcrypt password hashing)
- Search bus trips by origin, destination, and travel date
- Visual seat selection with live availability
- Enter passenger details — Adult, Student, Senior, Child fares
- **5 payment methods:** Credit Card, Debit Card, Internet Banking, Online Wallet (PayPal, Paytm), Cash
- View and print e-ticket
- My Bookings — view upcoming and past trips, cancel bookings
- Notification inbox — confirmations, cancellations, schedule changes
- Profile — update name, phone, change password

### Admin Side
- Dashboard — booking stats, revenue, active buses, pending refunds
- Manage Buses — add, edit, delete, assign driver, upload image
- Manage Routes — add, edit, delete, set pricing
- Manage Schedules — add, edit, delete, send change notifications
- Manage Drivers — add, edit, delete driver profiles
- Manage Bookings — view all bookings, update refund status
- Manage Users — view registered customers, search, view booking history
- Reports — popular routes, monthly revenue, passenger types, payment methods, bus utilisation

---

## Security Notes

- All database queries use **prepared statements** (no raw SQL string injection)
- Passwords stored as **bcrypt hashes** via `password_hash()` / `password_verify()`
- All output escaped with `htmlspecialchars()` to prevent XSS
- Seat availability re-checked server-side at payment to prevent double-booking
- File uploads (bus images) validated for MIME type and size

---

## Input Validation

All forms are validated both server-side (PHP) and client-side (HTML5 `required` attributes).

- Email format checked with `filter_var()`
- Passwords: minimum 8 characters, current password verified before change
- Date inputs: travel date cannot be in the past
- Bus number: unique constraint at DB level and checked in PHP
- Bus image uploads: MIME type must be JPG/PNG/GIF/WEBP, max 5 MB

---

## Known Limitations

- **Payment is simulated.** No real payment gateway is connected. Booking is confirmed immediately after selecting a method.
- **Notifications are in-app only.** No real email or SMS is sent. Notifications appear in the user's notification inbox. This is a local XAMPP prototype.
- **Bus image upload** saves to `assets/images/buses/` on the server. This works locally on XAMPP but would need proper file permission handling on a live server.
- **No pagination** on admin tables. Fine for a prototype with limited data, but would be needed for production.
- **No two-factor authentication.** Standard username/password login only.

---

## Pages to Test Before Submission

| Page                    | URL path                        | What to check                                      |
|-------------------------|---------------------------------|----------------------------------------------------|
| Home                    | `/`                             | Search form, popular routes, team section, footer  |
| Search results          | `/search.php`                   | Results list, "no trips found" state               |
| Seat selection          | `/seat_selection.php`           | Visual seat map, unavailable seats blocked         |
| Passenger details       | `/passenger_details.php`        | Fare calculation per passenger type                |
| Payment                 | `/payment.php`                  | All 5 payment methods selectable                   |
| E-ticket                | `/ticket.php`                   | Print button, all booking details shown            |
| My Bookings             | `/my_bookings.php`              | Upcoming + past tabs, cancel button on upcoming    |
| Notifications           | `/notifications.php`            | Unread count, mark as read                         |
| Profile                 | `/profile.php`                  | Update name/phone, change password with validation |
| Admin Dashboard         | `/admin/dashboard.php`          | Stats cards, alert badges                          |
| Admin Buses             | `/admin/buses.php`              | Add bus with image upload, assign driver           |
| Admin Reports           | `/admin/reports.php`            | Charts and tables render                           |

---

## File Structure

```
dysonconnect_v3/
├── index.php                  Home page
├── search.php                 Trip search results
├── bus_details.php            Trip/bus details
├── seat_selection.php         Seat picker
├── passenger_details.php      Passenger info and fares
├── payment.php                Payment selection
├── ticket.php                 E-ticket view and print
├── my_bookings.php            Booking history and cancellation
├── notifications.php          In-app notifications
├── routes.php                 All routes listing
├── profile.php                User profile and password change
├── login.php                  Login page
├── register.php               Registration page
├── logout.php                 Logout handler
│
├── admin/
│   ├── dashboard.php
│   ├── buses.php
│   ├── routes.php
│   ├── schedules.php
│   ├── drivers.php
│   ├── bookings.php
│   ├── users.php
│   ├── reports.php
│   ├── admin_header.php
│   └── admin_footer.php
│
├── includes/
│   ├── db_connect.php         Database connection + BASE_URL
│   ├── functions.php          Shared helper functions
│   ├── header.php             Site header/nav
│   ├── footer.php             Site footer
│   └── auth_check.php        Login/role guards
│
├── assets/
│   ├── css/style.css
│   ├── js/script.js
│   └── images/
│
└── dysonconnect_v3.sql        Database schema + seed data
```

---

## Notes for the Marker

- No PHP framework has been used anywhere. Everything is plain PHP.
- The database file is `dysonconnect_v3.sql`. This is the only SQL file needed.
- The project was built and tested on XAMPP 8.x with PHP 8.1+ and MariaDB 10.4+.
- All payment and notification functionality is simulated — this is intentional for a local assessment prototype.
