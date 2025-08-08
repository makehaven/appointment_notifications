# Appointment Notifications Module

## Overview

The `appointment_notifications` module is a custom Drupal module designed to send email notifications for appointments within a Drupal site. It handles notifications for both users and hosts when appointments are scheduled or canceled, and it sends a special notification when a problem is reported during an appointment. The module ensures that emails are only sent when the relevant fields change, preventing duplicate notifications.

## Features

- **Appointment Scheduling Notifications**: Sends an email to the attendee (user) and the host (volunteer) when an appointment is scheduled.
- **Cancellation Notifications**: Sends an email to both the attendee and the host when an appointment is canceled. The attendee is CC'd in the cancellation notice sent to the host.
- **Problem Reporting Notifications**: Sends a notification to a designated staff email address when an issue is reported during an appointment, such as a volunteer being absent or the meeting being unsuccessful.
- **Development Mode**: When development mode is enabled, emails are logged and displayed on the screen instead of being sent.

## Installation

1. Place the `appointment_notifications` module in the `modules/custom` directory of your Drupal installation.
2. Enable the module via the Drupal admin interface or by using Drush:

drush en appointment_notifications


## Configuration

1. Navigate to **Configuration > System > Appointment Notifications** to configure the module.
2. Configure the following settings:
- **Sender Email Address**: The email address from which notifications will be sent.
- **Staff Notification Email**: The email address where problem notifications will be sent.
- **Email Templates**: Customize the subject and body of emails for:
  - Member scheduled notification
  - Host scheduled notification
  - Cancellation notification
- **Development Mode**: Enable this to prevent emails from being sent. Instead, they will be logged and displayed on the screen.

## Usage

### Scheduling Notifications

When an appointment is created or updated, the module will automatically send notifications:
- **Scheduling**: When a new appointment is scheduled, the attendee and host will receive notifications.
- **Cancellation**: When an appointment status changes from "scheduled" to "canceled," a notification will be sent to both the attendee and host, with the attendee CC'd in the host's email.
- **Problem Reporting**: If an appointment's result changes to a problem state (`volunteer_absent` or `met_unsuccessful`), a notification will be sent to the staff email address configured in the settings.

## Development

### Code Structure

- **Hook Implementations**: The module primarily uses hook implementations (`hook_entity_update()`) to detect changes in appointment entities and trigger email notifications.
- **Email Sending Functions**: The core functionality of sending emails is encapsulated in helper functions like `_appointment_notifications_send_email()` and `_appointment_notifications_send_problem_notice()`.

### Key Functions

- **`appointment_notifications_entity_update()`**: Monitors changes to appointment entities and triggers appropriate emails based on changes in status or results.
- **`_appointment_notifications_send_email()`**: Sends notifications for scheduling and cancellation. Handles development mode to log or display emails instead of sending them.
- **`_appointment_notifications_send_problem_notice()`**: Sends a notification to staff when a problem is reported during an appointment.

### Development Mode

- To enable development mode, ensure the `development_mode` setting is enabled in the configuration. This will prevent emails from being sent and instead log them and display them on the screen.

### Adding New Features or Modifying Existing Ones

1. **Adding New Notifications**: To add new types of notifications, follow the pattern used in the existing `_appointment_notifications_send_email()` function. Ensure that new email templates are added to the configuration form and schema.
2. **Updating Existing Notifications**: If you need to modify existing notifications, review the `_appointment_notifications_send_email()` and `_appointment_notifications_send_problem_notice()` functions to understand the current logic and placeholders used.

### Troubleshooting

- **Emails Not Sending**: Ensure that the mail system is properly configured in your environment. In development mode, emails are not sent but logged instead.
- **Duplicate Emails**: Check that the logic in `appointment_notifications_entity_update()` correctly prevents emails from being sent multiple times for the same event. The comparison of old and new field values is crucial to this.

### Future Development Notes

- **Extensibility**: Consider refactoring the email sending logic to allow for more flexible email triggers based on custom conditions. The current implementation is tied to specific field changes but could be expanded.
- **Testing**: Implement automated tests for the notification logic, particularly to ensure that emails are only sent when expected and that development mode behaves correctly.
