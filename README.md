# ESS IV Accommodation CRM

A web system for managing the 600-room ESS IV accommodation. It covers reservations, check-in and check-out, room status, maintenance, staff access control, email alerts and a Profit & Loss statement. Built with PHP 8.1+ and MySQL.

## Try it live

| Demo | What you get | Link |
|---|---|---|
| **Quick look** (in your browser, nothing to install) | The CRM screens with example data: dashboard and alerts, booking calendar, reservations, check-in/out, room status, maintenance. Changes stay in your browser. | **https://cd5wtsnct4-bot.github.io/ess-iv-crm/** |
| **Full system** (GitHub Codespaces, free GitHub account needed) | The real PHP + MySQL app: sign-in, user access levels, email alerts (caught in a demo mailbox), Finance / Profit & Loss | [![Open in GitHub Codespaces](https://github.com/codespaces/badge.svg)](https://codespaces.new/cd5wtsnct4-bot/ess-iv-crm?quickstart=1) |

### Using the full-system demo

1. Click **Open in GitHub Codespaces**, then **Create codespace**. The first start takes about 3–5 minutes while PHP and MySQL are set up.
2. When it's ready, the CRM opens in a new browser tab. If it doesn't, open the **Ports** tab and click the globe icon next to **ESS IV CRM (80)**.
3. Sign in with one of the demo accounts:

   | Username | Password | Access |
   |---|---|---|
   | `admin` | `Demo-Admin-2026` | Everything, including Users, Email alerts and Finance |
   | `frontdesk` | `Demo-Desk-2026` | Reservations (edit); Room status and Maintenance (view only); no Finance |
   | `maintenance` | `Demo-Maint-2026` | Room status and Maintenance (edit); Reservations (view only) |
   | `finance` | `Demo-Finance-2026` | Finance (edit); Reservations and Maintenance (view only) |

4. As `admin`, click **Load example data** on the Dashboard. This loads three months of bookings, cancellations, maintenance tickets and expenses.
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
- **Check-in / check-out** lists. Checking a guest out marks the room *Dirty* for housekeeping.
- **Room status:** all 600 rooms, colour-coded. Rooms can be marked *Out of service* or *Under maintenance*, with a reason, details and an expected return date.
- **Maintenance:** tickets with priority, team, due date and cost. A ticket can take its room offline. There's also a preventive maintenance schedule.
- **Users & access:** *No access*, *View only* or *Can edit* per person for Reservations, Room status, Room maintenance and Finance. The server enforces this as well as hiding tabs.
- **Finance (Profit & Loss):**
  - room revenue for nights actually stayed, plus cancellation and no-show fees
  - expenses: maintenance costs plus operating expenses
  - net profit compared with the previous period
  - occupancy, average rate per night (ADR), revenue per available room (RevPAR), cancellation rate
  - breakdowns by month, block and company, and a CSV export
- **Email:** every alert, a daily summary and a notification for every change, sent by SMTP to the configured recipients, with a log of every email.

## Install on your own web server

See **[DEPLOY.md](DEPLOY.md)** for the step-by-step guide. It covers cPanel/shared hosting and Linux servers (Apache or Nginx).
