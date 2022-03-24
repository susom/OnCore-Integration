# OnCoreIntegration

An external module that manages syncing patient data between REDCap projects and their respective OnCore counterparts

OnCore Fields Definition Example:

```json
{
    "subjectDemographicsId": {
        "alias": "",
        "description": "subject Demographics Id",
        "oncore_field_type": [
            "int"
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
