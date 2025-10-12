# Questionnaire Import Guide

This guide explains how administrators can import questionnaires using the EPSS HR Assessment builder.

## Prerequisites

- Administrator access to the HR Assessment platform.
- A questionnaire definition file in the supported XML format that follows the structure described below.
- Optional: start from the Excel planning sheet (`scripts/download_questionnaire_template.php`) to capture sections, items, and work-function assignments before generating the XML payload.
- Familiarity with the work functions (e.g., WIM, ICT, HRM) that determine questionnaire availability.

## Preparing Your XML File

1. Use the structure outlined in this guide (or generate XML from the Excel planning sheet) to ensure the required elements are present.
2. Update the `<title>` and `<description>` elements to describe your questionnaire.
3. Review the `<workFunction>` entries to confirm the target audiences. Include `wim` if the questionnaire should remain available to the Warehouse & Inventory Management (WIM) cadre by default.
4. For each `<section>` element, supply a unique `order` and update the `title` and `description` values.
5. Within each `<item>`:
   - Set a unique `linkId` (used as a stable identifier).
   - Choose a `type` of `likert`, `choice`, `text`, `textarea`, or `boolean`.
   - Provide `text` for the question prompt.
   - For `likert` or `choice` items, define `<option>` values in the desired order.
6. Validate that the XML is well-formed before uploading.

## Using the Excel Planning Template

The downloadable Excel workbook contains a single sheet with the following columns:

- **Questionnaire Title** and **Questionnaire Description** — applied to the root questionnaire record.
- **Section Title** and **Section Description** — create or update named sections. Leave blank to assign items to the questionnaire root.
- **Item LinkId**, **Item Text**, **Item Type**, **Allow Multiple**, and **Weight (%)** — define question prompts and scoring metadata.
- **Options (semicolon separated)** — supply response options for `likert` or `choice` items.
- **Work Functions (semicolon separated)** — list the cadres that should receive the questionnaire (e.g., `general_service;hrm`).

Each row represents a single questionnaire item. Duplicate the questionnaire and section details across rows as needed. After the structure is reviewed, translate the entries into the XML format described below so the importer can create the questionnaire and its related sections automatically.

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
