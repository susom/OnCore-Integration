# OnCoreIntegration

This is a system-wide REDCap external module that will periodically scan OnCore for protocols that have the same IRB# as the respective REDCap project.
If found, an option to link the 2 projects will be presented so that Subject data can be shared between the two.  The data on the OnCore side will always be the "Source of Truth".


## Field Mapping
Once linked, some initial configuration will be necessary.  OnCore data will be found for a number of properties either as `text` or as an `array`.  On the REDCap side the matching `field` may not necessarily match if it is an enumerated field.  So a manual mapping between the OnCore properties and the REDCap fields will need to be completed in the field mapping page before the EM can function properly.

### Pull Mapping
In order to `PULL` data from OnCore to REDCap.  We select the relevant OnCore Properties from the drop-down one by one, and select the appropriate REDCap field from another drop-down.  If there are enumerated fields, a second level UI will appear so that the individual options can be mapped.

### Push Mapping
In order to `PUSH` data Subject data from REDCap to OnCore.   We need to satisfy the **miniumum** field requirements by mapping a core subset of OnCore Properties to their REDCap counterparts.  If the requirements are not met, the push will fail.

## Adjudication
The EM will take the mapping data and do a daily (or manual) scan/sync against the OnCore API using a subject's **MRN** to find matches.  If data is found it will be assigned 3 possible statuses; "Partial Match", "OnCore only", "REDCap only".

### Partial Match
The data was found in both the REDCap Project and it's OnCore counterpart.  But there was a discrepency in one or more propeties/fields.   In these instances, OnCore being the "source of truth" will overwrite the data in the mapped REDCap field.  Unless that subject is "excluded".

### OnCore Only
Subjects are found in the OnCore Protocol but no matching MRN was found in the REDCap project.  In this instance all the subjects and their mapped data are pulled into REDCap from OnCore unless "excluded".

### REDCap Only
Subjects are found in the REDCap project but no matching MRN was found in the OnCore Protocol.  In this instance all the subjects and their mapped data are pushed into OnCore from REDCap unless "excluded".



OnCore Fields Definition Example:
```json
{
    "subjectDemographicsId": {
        "alias": "",
        "description": "subject Demographics Id",
        "oncore_field_type": [
            "string"
        ],
        "oncore_valid_values": [],
        "required": "false"
    },
    "subjectSource": {
        "alias": "",
        "description": "subject Source",
        "oncore_field_type": [
            "text"
        ],
        "oncore_valid_values": [],
        "required": "true"
    },
    "mrn": {
        "alias": "",
        "description": "MRN",
        "oncore_field_type": [
            "text"
        ],
        "oncore_valid_values": [],
        "required": "true"
    },
    "lastName": {
        "alias": "",
        "description": "last Name",
        "oncore_field_type": [
            "text"
        ],
        "oncore_valid_values": [],
        "required": "true"
    },
    "firstName": {
        "alias": "",
        "description": "first Name",
        "oncore_field_type": [
            "text"
        ],
        "oncore_valid_values": [],
        "required": "true"
    },
    "middleName": {
        "alias": "",
        "description": "middle Name",
        "oncore_field_type": [
            "text"
        ],
        "oncore_valid_values": [],
        "required": "true"
    },
    "suffix": {
        "alias": "",
        "description": "suffix",
        "oncore_field_type": [
            "text"
        ],
        "oncore_valid_values": [],
        "required": "false"
    },
    "birthDate": {
        "alias": "",
        "description": "Date of Birth",
        "oncore_field_type": [
            "text"
        ],
        "oncore_valid_values": [],
        "required": "true"
    },
    "approximateBirthDate": {
        "alias": "",
        "description": "approximate Birth Date",
        "oncore_field_type": [
            "bool"
        ],
        "oncore_valid_values": [],
        "required": "false"
    },
    "birthDateNotAvailable": {
        "alias": "",
        "description": "birth Date Not Available",
        "oncore_field_type": [
            "bool"
        ],
        "oncore_valid_values": [],
        "required": "false"
    },
    "expiredDate": {
        "alias": "",
        "description": "expired Date",
        "oncore_field_type": [
            "text"
        ],
        "oncore_valid_values": [],
        "required": "false"
    },
    "approximateExpiredDate": {
        "alias": "",
        "description": "approximate Expired Date",
        "oncore_field_type": [
            "bool"
        ],
        "oncore_valid_values": [],
        "required": "false"
    },
    "lastDateKnownAlive": {
        "alias": "",
        "description": "last Date Known Alive",
        "oncore_field_type": [
            "text"
        ],
        "oncore_valid_values": [],
        "required": "false"
    },
    "ssn": {
        "alias": "",
        "description": "Social Security Number",
        "oncore_field_type": [
            "text"
        ],
        "oncore_valid_values": [],
        "required": "false"
    },
    "gender": {
        "alias": "",
        "description": "Gender",
        "oncore_field_type": [
            "text"
        ],
        "oncore_valid_values": [
            "Male",
            "Female",
            "Unknown"
        ],
        "required": "true"
    },
    "ethnicity": {
        "alias": "",
        "description": "Ethnicity",
        "oncore_field_type": [
            "text"
        ],
        "oncore_valid_values": [
            "Hispanic or Latino",
            "Non-Hispanic",
            "NOTE - Use Unknown For Not Reported",
            "Unknown"
        ],
        "required": "true"
    },
    "race": {
        "alias": "",
        "description": "Race",
        "oncore_field_type": [
            "array"
        ],
        "oncore_valid_values": [
            "White",
            "Black or African American",
            "Native Hawaiian or Other Pacific Islander",
            "Asian",
            "American Indian or Alaska Native",
            "Not Reported",
            "Unknown"
        ],
        "required": "true"
    },
    "subjectComments": {
        "alias": "",
        "description": "subject Comments",
        "oncore_field_type": [
            "text"
        ],
        "oncore_valid_values": [],
        "required": "false"
    },
    "additionalSubjectIds": {
        "alias": "",
        "description": "additional Subject Ids",
        "oncore_field_type": [
            "array"
        ],
        "oncore_valid_values": [],
        "required": "false"
    },
    "streetAddress": {
        "alias": "",
        "description": "street Address",
        "oncore_field_type": [
            "text"
        ],
        "oncore_valid_values": [],
        "required": "false"
    },
    "addressLine2": {
        "alias": "",
        "description": "address Line 2",
        "oncore_field_type": [
            "text"
        ],
        "oncore_valid_values": [],
        "required": "false"
    },
    "city": {
        "alias": "",
        "description": "City",
        "oncore_field_type": [
            "text"
        ],
        "oncore_valid_values": [],
        "required": "false"
    },
    "state": {
        "alias": "",
        "description": "State",
        "oncore_field_type": [
            "text"
        ],
        "oncore_valid_values": [],
        "required": "false"
    },
    "zip": {
        "alias": "",
        "description": "ZIP",
        "oncore_field_type": [
            "text"
        ],
        "oncore_valid_values": [],
        "required": "false"
    },
    "county": {
        "alias": "",
        "description": "County",
        "oncore_field_type": [
            "text"
        ],
        "oncore_valid_values": [],
        "required": "false"
    },
    "country": {
        "alias": "",
        "description": "Country",
        "oncore_field_type": [
            "text"
        ],
        "oncore_valid_values": [],
        "required": "false"
    },
    "phoneNo": {
        "alias": "",
        "description": "Phone",
        "oncore_field_type": [
            "text"
        ],
        "oncore_valid_values": [],
        "required": "false"
    },
    "alternatePhoneNo": {
        "alias": "",
        "description": "alternate Phone",
        "oncore_field_type": [
            "text"
        ],
        "oncore_valid_values": [],
        "required": "false"
    },
    "email": {
        "alias": "",
        "description": "Email",
        "oncore_field_type": [
            "text"
        ],
        "oncore_valid_values": [],
        "required": "false"
    }
}
```
