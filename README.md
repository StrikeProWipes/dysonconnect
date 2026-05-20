# DysonConnect – Online Bus Booking System

**DWIN309 – Developing Web Information Systems**
Assessment 4 – Group Project
Kent Institute Australia

---

## Group Members

| Student ID | Name | Contribution |
|---|---|---|
| K241034 | Bibek Subedi | Admin Panel, Database Design & System Integration |
| K240381 | Wasik Gaus | Bus Search, Route Listing & Seat Selection |
| K241054 | Santosh Silwal | Passenger Booking Flow & Payment Module |
| K231952 | Sushil Bhusal | User Login, Registration & Profile Management |

---

## About the Project

DysonConnect is a student-built prototype online bus booking system developed for the Dyson Group business case. The Dyson Group is a fictional family-run bus operator serving intercity routes across Victoria and New South Wales.

The system lets customers search for bus trips, choose a seat, enter passenger details, make a simulated payment, and get a printable e-ticket. Customers can also view booking history, cancel eligible bookings, and receive in-app notifications.

An admin panel is included for managing buses, routes, schedules, drivers, bookings, users, and reports.

The project is built with plain PHP and MySQL. No PHP framework has been used.

---

## Technologies Used

- PHP
- MySQL / MariaDB
- HTML / CSS / JavaScript
- XAMPP / phpMyAdmin

---

## Setup Instructions

1. Copy the project folder into your XAMPP htdocs directory:
   `C:\xampp\htdocs\dysonconnect_v3\`

2. Start **Apache** and **MySQL** from the XAMPP Control Panel.

3. Open phpMyAdmin:
   `http://localhost/phpmyadmin`

4. Click **Import**, select `dysonconnect_v3.sql`, and click **Go**.
   The database, tables, and sample data will be created automatically.

5. Open the project in your browser:
   `http://localhost/dysonconnect_v3`

> Only import `dysonconnect_v3.sql` — this is the final database file.

---

## Database Details

| Setting | Value |
|---|---|
| Database name | dysonconnect |
| Host | localhost |
| Username | root |
| Password | *(empty)* |
| SQL file | dysonconnect_v3.sql |

---

## Test Login Details

**Admin account**
- Email: `bibek.subedi@student.kent.edu.au`
- Password: `Bibek@Admin1`
- Admin panel: `http://localhost/dysonconnect_v3/admin/dashboard.php`

**Customer account**
- Email: `wasik.gaus@student.kent.edu.au`
- Password: `Wasik@Pass1`

Additional demo customer accounts are included in the database seed data with password `Pass@1234`.

---

## Main Features

**Customer side**
- Register and log in
- Search bus trips by route and travel date
- Select available seats from a visual seat map
- Enter passenger details with fare calculation by type (Adult, Student, Senior, Child)
- Choose a simulated payment method (card, debit, internet banking, wallet, cash)
- View and print e-ticket
- View booking history and cancel eligible bookings
- View in-app notifications
- Update profile and change password

**Admin side**
- Dashboard with booking and revenue summary
- Manage buses, routes, schedules, and drivers
- Manage bookings and refund status
- Manage registered customers
- View reports

---

## Known Limitations

- Payment is simulated. No real payment gateway is connected.
- Notifications appear inside the website only. No real email or SMS is sent.
- The project is built for local XAMPP testing and is not configured for live hosting.
- Some features are prototype-level and would need further development for production use.

---

*This project was created for the final assessment for DWIN309 – Developing Web Information Systems at Kent Institute Australia.*
