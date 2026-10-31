# Zenodo for OJS 3.4

An adaptation of the PKP Zenodo plugin for **OJS 3.4.0-10**, supporting metadata export, PDF uploads, and synchronization of Zenodo-generated DOIs with OJS publications.

This repository contains a community adaptation of the original plugin. Preserve the original copyright notices and license. Compatibility work targets OJS 3.4.0-10; other versions require separate validation.

## Project status

The installation used during development confirmed plugin loading, JSON export, PDF upload to the Sandbox, retrieval of a test DOI, and storage of a reserved production DOI in OJS.

These checks do not cover every configuration. Communities, embargoes, automatic publishing, and interactions with other plugins require additional testing.

**Pending:** identifying records deposited manually in Zenodo and linking them to OJS after a DOI-based preview. This feature is not implemented yet.

## Features

- JSON export of available titles, translations, authors, text affiliations, ORCID identifiers, abstracts, keywords, bibliographic references, DOIs, and journal metadata.
- Upload of article galley files to Zenodo.
- Creation and updating of drafts linked to an article.
- DOI reservation through Zenodo when **Zenodo DOIs** is enabled.
- Storage of production DOIs in OJS publications without replacing a different existing DOI.
- Separation of Sandbox and production records.

Deposits are **synchronous**: uploads run during the browser request. This adaptation does not require a queue worker and does not automatically retry failed deposits.

## Installation

1. Place the plugin files in `plugins/generic/zenodo`. Even if the repository is named `zenodo-ojs34`, the installed directory must be named **`zenodo`**.
2. From the OJS root directory, register the plugin version, filters, and installation settings:

   ```bash
   php lib/pkp/tools/installPluginVersion.php plugins/generic/zenodo/version.xml
   ```

3. Enable Zenodo under **Settings → Website → Plugins → Generic Plugins**.
4. Open **Tools → Import/Export → Zenodo** and configure access.

Copying the files without registering the filters may cause `No export filter found`. The installation command updates plugin records in the OJS database; it does not deposit anything in Zenodo.

## Initial configuration

Start with an account and API key from [Zenodo Sandbox](https://sandbox.zenodo.org/).

- Enter the Sandbox API key and enable **Test Mode**.
- Enable **Zenodo DOIs** if Zenodo should reserve a DOI for an article that does not already have one.
- Leave **Automatic Publishing** disabled to review the draft before publication.
- Leave the community field empty for the first test.
- Keep **Automatic Deposit** disabled: this package does not include the scheduling configuration needed to make that option operational.

To use [production Zenodo](https://zenodo.org/), replace the API key with the production account's key and disable **Test Mode**. The two environments use separate credentials. Do not commit API keys, passwords, OJS configuration files, or database copies to this repository.

## Article requirements

Deposits require a title, at least one author, a publication date, and a PDF galley in the article's primary language. The PDF must be classified as article text, rather than supplementary or dependent material.

The current interface lists **articles published in OJS**. There is no deposit action integrated into the production/editing stage yet. Do not publish an article solely to test the plugin.

Also complete the publisher and ISSN fields in the journal settings. Check the metadata for each language: the plugin exports the stored text and does not translate it automatically.

## Export, Register, and Mark as registered

| Action | Result |
| --- | --- |
| **Export** | Generates metadata JSON; does not upload files or obtain a DOI. |
| **Register** | Deposits in the configured environment, uploads files, and synchronizes the DOI. |
| **Mark as registered** | Changes the local status; does not deposit in Zenodo or automatically link a manual deposit. |

A **Registered** status indicates the local deposit outcome. It does not, by itself, mean the record has been published in Zenodo. Button labels may vary with the OJS interface language.

## Workflow with a Zenodo-generated DOI

1. Prepare the article metadata and PDF in OJS.
2. Enable **Zenodo DOIs** and leave **Automatic Publishing** disabled.
3. Select the article and click **Register**.
4. Review the Zenodo draft and the returned DOI.
5. In production, check **Publication → Identifiers → DOI** in OJS.
6. Add that DOI to the final PDF and replace the galley in OJS.
7. Click **Register** again to update the same linked draft.
8. Review the file and metadata in Zenodo, then publish the record when ready.

**A reserved DOI may appear on the OJS website before it is active.** Reserving a DOI is not the same as publishing the Zenodo record.

If an article already has a different DOI, synchronization will not automatically replace it. Review the error before repeating the deposit.

## Sandbox and production

Sandbox DOIs are stored in plugin fields and identified as test DOIs in the export list. They are not written to the publication's official DOI field and must not be used in the final PDF.

When moving from Sandbox to production, depositing creates a production association. It does not convert the test record into a production record. The reverse operation is blocked to prevent replacing a production association with a test association.

## Updates and manual deposits

While a linked draft exists, another deposit updates that draft and replaces its files with the current OJS galley files. After the record is published in Zenodo, the current workflow updates metadata and does not replace published files.

Manually deposited articles may still show an incorrect status or record ID in the plugin. **Do not use Register to fix this without checking the association:** another record could be created. Importing existing associations by DOI is a pending improvement.

## Limitations

- No stage-based or major/minor version model from newer OJS APIs.
- No structured export of data citations, funding, or ROR metadata from newer APIs.
- Authors are exported as personal creators; newer contributor-role mappings are not supported.
- No deposit queue or automatic retry after failures.
- Automatic registration requires additional scheduling configuration, which is absent from this package.
- Files and diagrams inherited from the original project may describe the former queued workflow; they do not override this README.

## Verification

From the OJS root directory, check the installed PHP files:

```bash
find plugins/generic/zenodo -type f -name '*.php' -exec php -l {} \;
```

`php -l` checks syntax only. It does not verify OJS class availability, filter registration, database persistence, or acceptance by Zenodo.

Development also included local class-loading checks against OJS 3.4.0-10 base classes and tests with mocked database and HTTP services. These checks do not constitute a complete automated test suite distributed with this repository.

## References

- [Zenodo](https://zenodo.org/)
- [Zenodo Sandbox](https://sandbox.zenodo.org/)
- [InvenioRDM API documentation](https://inveniordm.docs.cern.ch/reference/rest_api_index/)
- [OJS 3.4.0-10 source code](https://github.com/pkp/ojs/tree/3_4_0-10)

## License

Distributed under the **GNU General Public License v3**, as provided in [LICENSE](LICENSE). Original copyright and authorship notices remain in the plugin files.
