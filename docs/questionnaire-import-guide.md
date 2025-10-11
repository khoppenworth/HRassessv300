# Questionnaire Import Guide

This guide explains how administrators can import questionnaires using the EPSS HR Assessment builder.

## Prerequisites

- Administrator access to the HR Assessment platform.
- A questionnaire definition file in the supported XML format that conforms to the sample template provided with the application (`assets/templates/sample_questionnaire_template.xml`).
- Familiarity with the work functions (e.g., WIM, ICT, HRM) that determine questionnaire availability.

## Preparing Your XML File

1. Start from the provided sample template to ensure the required structure is present.
2. Update the `<title>` and `<description>` elements to describe your questionnaire.
3. Review the `<workFunction>` entries to confirm the target audiences. Include `wim` if the questionnaire should remain available to the Warehouse & Inventory Management (WIM) cadre by default.
4. For each `<section>` element, supply a unique `order` and update the `title` and `description` values.
5. Within each `<item>`:
   - Set a unique `linkId` (used as a stable identifier).
   - Choose a `type` of `likert`, `choice`, `text`, `textarea`, or `boolean`.
   - Provide `text` for the question prompt.
   - For `likert` or `choice` items, define `<option>` values in the desired order.
6. Validate that the XML is well-formed before uploading.

## Import Steps

1. Sign in as an administrator and navigate to **Manage Questionnaires** from the drawer.
2. Scroll to the **FHIR Import** card.
3. Click **Choose File** and select your prepared XML file.
4. Press **Import** to upload the file. The system will parse sections, items, options, and work function assignments.
5. After a successful import you will see a confirmation banner, and the imported questionnaire will open automatically in the builder tabs for review.

## Post-Import Checklist

- Verify that the correct work functions (including `wim`) are assigned; adjust them in the builder if needed.
- Review each section and question for accuracy.
- Use the **Save Changes** button to persist edits or **Publish** to make the questionnaire available to end users.
- Download the latest template or this guide at any time from the import card for future reference.

For troubleshooting or additional support, contact the system maintainer or refer to the project README.
