# ESS IV Accommodation CRM

A web system for managing the 600-room ESS IV accommodation. It covers reservations (including bulk group bookings), check-in and check-out, guest profiles with saved special requests, room status and maintenance. It also covers catering and the kitchen, stock and purchasing, cleaning and housekeeping, staff access control, email alerts, and a Profit & Loss statement. Built with PHP 8.1+ and MySQL.

## Try it live

### Full live demo for clients: one public link, no sign-up for viewers

[![Deploy to Render](https://render.com/images/deploy-to-render-button.svg)](https://render.com/deploy?repo=https://github.com/cd5wtsnct4-bot/ess-iv-crm)

This runs the **complete system**: PHP and a MySQL-compatible database, with every module and example data loaded automatically.

1. Click **Deploy to Render**, then sign in to Render with your GitHub account. The free plan is enough.
2. Click **Deploy Blueprint**. The first build takes about 5–8 minutes.
3. Render gives the demo a public address, such as `https://ess-iv-crm-demo.onrender.com`. Send that link to your client.
4. On the sign-in page, the client clicks **Use** next to any department (admin, front desk, housekeeping, maintenance, kitchen, finance) to see what that team sees.

How the demo behaves:

- **Emails stay inside the demo.** Every alert and notification is kept in the **Demo mailbox**: sign in as `admin`, then click **Email alerts**. Nothing is sent to real inboxes.
- **It sleeps when idle.** On the free plan, the demo goes to sleep after about 15 minutes without visitors. The next visit wakes it in about a minute, with fresh example data, so every client starts from the same state.
- **Run it on your own computer** with Docker: `docker build -f demo/Dockerfile -t ess-iv-crm-demo . && docker run --rm -p 8080:8080 ess-iv-crm-demo`, then open http://localhost:8080.

### Other ways to look

| Demo | What you get | Link |
|---|---|---|
| **Quick look** (in your browser, nothing to install) | The CRM screens with example data: dashboard and alerts, booking calendar, reservations, check-in/out, room status, maintenance. Changes stay in your browser. | **https://cd5wtsnct4-bot.github.io/ess-iv-crm/** |
| **Full system** (GitHub Codespaces, free GitHub account needed) | The real PHP + MySQL app with every module: sign-in and user access levels, bulk bookings, guest profiles, Operations (catering, stock, cleaning), email alerts (caught in a demo mailbox), Finance / Profit & Loss | [![Open in GitHub Codespaces](https://github.com/codespaces/badge.svg)](https://codespaces.new/cd5wtsnct4-bot/ess-iv-crm?quickstart=1) |

### Using the full-system demo

1. Click **Open in GitHub Codespaces**, then **Create codespace**. The first start takes about 3–5 minutes while PHP and MySQL are set up.
2. When it's ready, the CRM opens in a new browser tab. If it doesn't, open the **Ports** tab and click the globe icon next to **ESS IV CRM (80)**.
3. Sign in with one of the demo accounts:

   | Username | Password | Access |
   |---|---|---|
   | `admin` | `Demo-Admin-2026` | Everything, including Users, Email alerts and Finance |
   | `frontdesk` | `Demo-Desk-2026` | Reservations (edit); Room status and Maintenance (view only); no Finance |
   | `maintenance` | `Demo-Maint-2026` | Room status and Maintenance (edit); Reservations (view only) |
   | `finance` | `Demo-Finance-2026` | Finance (edit); Reservations and Maintenance (view only); Operations (view only) |
   | `kitchen` | `Demo-Kitchen-2026` | Kitchen & catering and Stock & purchasing (edit); Reservations (view only) |
   | `housekeeping` | `Demo-House-2026` | Cleaning & housekeeping and Room status (edit); Stock (view only) |

4. As `admin`, click **Load example data** on the Dashboard. This loads three months of bookings and cancellations, 150 guest profiles, maintenance tickets and expenses, and 60 days of stock, deliveries, meals, temperature checks and cleaning.
5. To see the alert and notification emails, open the **Ports** tab and open **Demo mailbox (8025)**. In the demo, every email is caught there and nothing is sent to the internet.

These demo passwords are for the Codespaces demo only. A real installation uses `install.php`, where you choose your own passwords.

## Features

- **Dashboard:**
  - occupancy tonight and a 14-night forecast, plus room status by block
  - alerts with dates: overdue check-outs, missed arrivals, check-ins and check-outs due today and in the next 7 days, overdue repairs, rooms due back in service, preventive maintenance due
- **Booking calendar:** a grid of 600 rooms against dates. Click an empty day to book that room.
- **Reservations:**
  - every guest, stay, billing and status field
  - only rooms that are free for the dates can be chosen, and double bookings are refused
- **Bulk (group) bookings:**
  - book many rooms for one client in one step: room counts by type, preferred blocks, rates, and a list of guest names
  - the system picks free rooms and keeps the group together
  - all rooms are booked, or none are
  - check in, check out or cancel the whole group at once
- **Guests:**
  - a profile for each returning guest, recognised by ID/passport number, email, or phone and surname
  - saved special requests, dietary needs and room preferences are added automatically to every new booking
  - **Book again** in one click
- **Check-in / check-out** lists. Checking a guest out marks the room *Dirty* for housekeeping.
- **Room status:** all 600 rooms, colour-coded. Rooms can be marked *Out of service* or *Under maintenance*, with a reason, details and an expected return date.
- **Maintenance:** tickets with priority, team, due date and cost. A ticket can take its room offline. There's also a preventive maintenance schedule.
- **Users & access:** *No access*, *View only* or *Can edit* per person for Reservations, Room status, Room maintenance, Finance, Kitchen & catering, Stock & purchasing, and Cleaning & housekeeping. The server enforces this as well as hiding tabs.
- **Operations dashboard** for catering, the kitchen and cleaning supplies:
  - **Kitchen & catering:** a weekly meal planner with portions forecast from in-house guests, meals served, food cost per meal, and a food-safety temperature log
  - **Stock & purchasing:** stock items with reorder levels, receive/issue/count/waste movements, and purchase orders from low stock (receiving them updates stock and the P&L), plus usage reports
  - **Cleaning & housekeeping:** a board of rooms to clean, a common-area cleaning schedule, and cleaning-supply issue and usage by area
  - **Alerts:**
    - food shortages, both below and close to reorder level, including items that will run out within a set number of days at current usage
    - expiring stock
    - temperature failures
    - overdue cleaning
    - late deliveries
- **Finance (Profit & Loss):**
  - room revenue for nights actually stayed, plus cancellation and no-show fees
  - expenses: maintenance costs, catering and cleaning-supply purchases, and other operating expenses
  - food cost per meal, cleaning cost per room-night, stock used compared with purchased, and wastage
  - net profit compared with the previous period
  - occupancy, average rate per night (ADR), revenue per available room (RevPAR), cancellation rate
  - breakdowns by month, block and company, and a CSV export
- **Email:** every alert, a daily summary and a notification for every change, sent by SMTP to the configured recipients, with a log of every email.

## Install on your own web server

See **[DEPLOY.md](DEPLOY.md)** for the step-by-step guide. It covers cPanel/shared hosting and Linux servers (Apache or Nginx).
