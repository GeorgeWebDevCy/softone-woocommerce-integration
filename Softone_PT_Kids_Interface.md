# SOFTONE – Interface Documentation (Redacted, with Domain)

**Company:** P.T. KIDS & TEEN’S FURNITURE LTD  
**Document:** API Integration Interface  
**Updated:** 10/09/2025  

---

## Table of Contents
1. [Download data from Softone](#1-download-data-from-softone)
   - [API Endpoint](#api-endpoint)
   - [Login](#login)
   - [Authenticate](#authenticate)
2. [API Directory](#2-api-directory)
   - [Softone Customers](#softone-customers)
   - [Softone Items](#softone-items)
3. [setData API – Sending Data to Soft1 ERP](#3-setdata-api-to-send-data-to-soft1-erp)
   - [Create Customer](#create-customer)
   - [Sending Sales Orders (SALDOC)](#sending-sales-orders-saldoc)
4. [Appendix – Field-by-field Response Explanations](#appendix--field-by-field-response-explanations)

---

## 1. Download Data from Softone

The data download from Softone is done via API calls through Web Services.

### API Endpoint

**Base Endpoint (from PDF):**
```
https://ptkids.oncloud.gr/s1services
```

> **Security Note:** Credentials, client IDs, and serial numbers remain redacted in this document.

**Username:** `[REDACTED_USER]`  
**Password:** `[REDACTED_PASS]`

---

### 1.1.1 Login

The login call must always be the first call. It returns a `clientID` used for the next call – `authenticate`.

**Call (structure):**
```json
{
  "service": "login",
  "username": "[REDACTED_USER]",
  "password": "[REDACTED_PASS]",
  "appId": 1000
}
```

**Response (redacted, representative):**
```json
{
  "success": true,
  "clientID": "[REDACTED_CLIENT_ID]",
  "objs": [
    {
      "COMPANY": "10",
      "COMPANYNAME": "P.T. KIDS & TEEN'S FURNITURE LTD",
      "BRANCH": "101",
      "BRANCHNAME": "BRANCH 1",
      "MODULE": "0",
      "MODULENAME": "Χρήστης",
      "REFID": "1000",
      "REFIDNAME": "WEB",
      "USERID": "1000",
      "FINALDATE": "",
      "ROLES": "",
      "XSECURITY": "0",
      "EXPTIME": "0"
    }
  ],
  "ver": "6.00.622.11560",
  "sn": "[REDACTED_SN]",
  "off": false,
  "pin": false,
  "appid": "1000"
}
```

**What it means (summary):**
- `success`: Boolean — login status.  
- `clientID`: **Session token**; required for `authenticate` and all subsequent requests until it expires.  
- `objs[0]`: Context of the authenticated environment (company/branch/module/reference user).  
- `ver`: SoftOne server version string.  
- `sn`: Server serial number (**redacted**).  
- `off`/`pin`: Flags related to offline/pin requirements (if any).  
- `appid`: Application identifier used in calls.

---

### 1.1.2 Authenticate

Must be the **second** call. Returns a (possibly refreshed) `clientID` used for **all subsequent** calls and binds the session to a specific company/branch/refid.

**Call (structure):**
```json
{
  "service": "authenticate",
  "clientID": "[REDACTED_CLIENT_ID]",
  "company": 10,
  "branch": 101,
  "module": 0,
  "refid": 1000
}
```

**Response (redacted, representative):**
```json
{
  "success": true,
  "clientID": "[REDACTED_CLIENT_ID]",
  "s1u": 1000,
  "hyperlinks": 1,
  "canexport": 1,
  "image": "[REDACTED_PATH].jpg",
  "companyinfo": "P.T. KIDS & TEEN'S FURNITURE LTD, BRANCH 1 | [REDACTED_ADDRESS]"
}
```

**What it means (summary):**
- `clientID`: Session token to be used from now on.  
- `s1u`: SoftOne user id bound to this session.  
- `hyperlinks`: Feature flag indicating UI hyperlink support (1 = on).  
- `canexport`: Feature flag indicating export permissions.  
- `image`: Path to the user/company image/logo.  
- `companyinfo`: Human-readable company/branch descriptor (address redacted).

---

## 2. API Directory

| Service            | Description                    |
|-------------------|--------------------------------|
| SoftOne Customers | Retrieve customer data         |
| SoftOne Items     | Retrieve item data             |
| Create Sales Order| Create a sales document        |
| Create Customers  | Create new customers           |

---

### 2.1.1 Softone Customers

Returns the details of a **single** customer (as per the PDF example) using a stored SQL name.

**Call:**
```json
{
  "service": "SqlData",
  "clientid": "[REDACTED_CLIENT_ID]",
  "appId": 1000,
  "SqlName": "getCustomers"
}
```

**Response (example from PDF):**
```json
{
  "success": true,
  "totalcount": 1,
  "rows": [
    {
      "TRDR": "2928",
      "CODE": "C00001",
      "NAME": "Cash Customer",
      "EMAIL": "[REDACTED_EMAIL]",
      "ADDRESS": "-",
      "CITY": "-",
      "ZIP": "-",
      "COUNTRY": "Cyprus",
      "PHONE1": "-"
    }
  ]
}
```

**Key fields:**
- `TRDR`: SoftOne **Customer ID** (integer).  
- `CODE`: Customer code (string).  
- `NAME`: Customer name.  
- `EMAIL`: Email (**redacted** in this doc).  
- `ADDRESS`, `CITY`, `ZIP`, `COUNTRY`: Location fields.  
- `PHONE1`: Primary phone.

---

### 2.1.2 Softone Items

Returns inventory/items via a stored SQL name (with optional parameters like `pMins`).

**Call:**
```json
{
  "service": "SqlData",
  "clientid": "[REDACTED_CLIENT_ID]",
  "appId": 1000,
  "SqlName": "getItems",
  "pMins": 99999
}
```

**Response (two sample rows from PDF):**
```json
{
  "success": true,
  "totalcount": 4,
  "rows": [
    {
      "MTRL": "328",
      "CODE": "I00003",
      "DESC": "Leclerc Baby Influencer Air",
      "RETAILPRICE": "489",
      "COMMERCATEGORY": "101",
      "COMMECATEGORY NAME": "Pushchairs",
      "SUBCATEGORY": "201",
      "SUBMECATEGORY NAME": "Leclerc Baby Influencer AIR",
      "BARCODE": "6090940359393",
      "SKU": "LEC20016",
      "COLOUR": "3",
      "COLOUR NAME": "Denim Blue",
      "SIZE": "-",
      "SIZE NAME": "-",
      "BRAND CODE": "101",
      "BRAND NAME": "Leclerc Baby",
      "Stock QTY": "0"
    },
    {
      "MTRL": "331",
      "CODE": "I00006",
      "DESC": "Leclerc Baby Influencer Air",
      "RETAILPRICE": "489",
      "COMMERCATEGORY": "101",
      "COMMECATEGORY NAME": "Pushchairs",
      "SUBCATEGORY": "201",
      "SUBMECATEGORY NAME": "Leclerc Baby Influencer AIR",
      "BARCODE": "6096314655627",
      "SKU": "LEC20019",
      "COLOUR": "6",
      "COLOUR NAME": "Olive Green",
      "SIZE": "-",
      "SIZE NAME": "-",
      "BRAND CODE": "101",
      "BRAND NAME": "Leclerc Baby",
      "Stock QTY": "0"
    }
  ]
}
```

**Key fields (mapping from PDF “Outputs”):**
- `MTRL`: SoftOne **Item ID**.  
- `CODE`: Item code.  
- `DESC` (aka “NAME” in outputs): Item name/description.  
- `RETAILPRICE` (aka “PRICER”): Retail price.  
- `COMMERCATEGORY` / `COMMECATEGORY NAME`: Category id/name.  
- `SUBCATEGORY` / `SUBMECATEGORY NAME`: Subcategory id/name.  
- `BARCODE` (`CODE1` in outputs): Barcode.  
- `SKU`: Internal/stock keeping unit.  
- `COLOUR` / `COLOUR NAME`: Colour id/name.  
- `SIZE` / `SIZE NAME`: Size code/name.  
- `BRAND CODE` / `BRAND NAME`: Brand code/name.  
- `Stock QTY` (aka `QTY1` in outputs): On-hand balance.  
- Additional outputs listed in PDF but not shown in these sample rows may include: `UTBL03`/name (SubSubCategory), `MTRSEASON`/name (Season), `CCCSOCYLODES` (Long Description), `REMARKS` (Description), `CCCSOCYRE2` (Notes), `BOOL01` (Show on web), `CCCSOCYSHDES` (Short Description).

---

## 3. setData API to Send Data to Soft1 ERP

Use `setData` to insert/modify records for native or custom objects. Only include fields necessary for the business case; SoftOne populates defaults for common fields.

### 3.1 Create Customer

**Call (from PDF; sensitive values redacted):**
```json
{
  "service": "setData",
  "clientID": "[REDACTED_CLIENT_ID]",
  "appID": 1000,
  "object": "CUSTOMER",
  "data": {
    "CUSTOMER": [
      {
        "CODE": "WEB00001",
        "NAME": "Web Test Customer",
        "COUNTRY": 57,
        "AREAS": 22,
        "PHONE01": "99999999",
        "PHONE02": "22232425",
        "SOCURRENCY": 47,
        "EMAIL": "[REDACTED_EMAIL]",
        "ADDRESS": "No address",
        "CITY": "Lemesos",
        "ZIP": "1010",
        "TRDCATEGORY": 1
      }
    ],
    "CUSEXTRA": [
      { "BOOL01": "1" }
    ]
  }
}
```

**Response:**
```json
{ "success": true, "id": "2937" }
```

**What it means:**
- `object: "CUSTOMER"`: Target SoftOne object.  
- `CUSTOMER[...]`: Main table payload (standard customer fields).  
- `CUSEXTRA[...]`: Related/extra table payload (custom/boolean flags etc.).  
- Response `id`: The newly created **TRDR** (customer id).

---

### 3.2 Sending Sales Order Documents (SALDOC)

**Call (from PDF; redacted clientID):**
```json
{
  "service": "setData",
  "clientID": "[REDACTED_CLIENT_ID]",
  "appID": 1000,
  "object": "SALDOC",
  "data": {
    "SALDOC": [
      {
        "SERIES": 3000,
        "TRDR": 2937,
        "VARCHAR01": 1234567,
        "TRNDATE": "2024-08-05 00:00:00",
        "COMMENTS": "No comment"
      }
    ],
    "MTRDOC": [
      { "WHOUSE": 101 }
    ],
    "ITELINES": [
      { "MTRL": 328, "QTY1": 2, "COMMENTS1": "test" },
      { "MTRL": 329, "QTY1": 1, "COMMENTS1": "" },
      { "MTRL": 331, "QTY1": 1, "COMMENTS1": "" }
    ]
  }
}
```

**Response:**
```json
{ "success": true, "id": "42" }
```

**What it means:**
- `object: "SALDOC"`: Sales document header table.  
- `SERIES`: Document series (defines numbering rules/doctype).  
- `TRDR`: Customer id (**must match** an existing `TRDR`, e.g., “2937” created above).  
- `VARCHAR01`: Free text/auxiliary field — in your usage this stores the **OpenCart Order ID** mapping.  
- `TRNDATE`: Transaction date/time.  
- `COMMENTS`: Header comments.  
- `MTRDOC`: Movement/warehouse context (e.g., `WHOUSE` 101).  
- `ITELINES`: Line items with `MTRL` (item id), `QTY1` (quantity), optional `COMMENTS1`.  
- Response `id`: The created sales document id.

---

## Appendix – Field-by-field Response Explanations

### Login (`service: "login"`)
- **success**: `true` if credentials are valid.  
- **clientID**: Session token for the next step (`authenticate`).  
- **objs[]**: Context list; commonly a single object describing:  
  - `COMPANY` / `COMPANYNAME`: Company id/name.  
  - `BRANCH` / `BRANCHNAME`: Branch id/name.  
  - `MODULE` / `MODULENAME`: Module id/name (e.g., core/user).  
  - `REFID` / `REFIDNAME`: Application ref (e.g., “WEB”).  
  - `USERID`: Logged-in user id.  
  - `FINALDATE`, `ROLES`, `XSECURITY`, `EXPTIME`: Misc. session/security metadata.  
- **ver**: SoftOne version.  
- **sn**: Server serial number (**redacted**).  
- **off**: Offline mode flag.  
- **pin**: Whether a pin is required.  
- **appid**: Echo of app id used.

### Authenticate (`service: "authenticate"`)
- **success**, **clientID**: As above (clientID may change).  
- **s1u**: SoftOne internal user id.  
- **hyperlinks**: UI flag indicating hyperlink rendering availability.  
- **canexport**: Permission flag for exports.  
- **image**: Relative path to a logo/image (not a URL).  
- **companyinfo**: Formatted company/branch and address string (address redacted here).

### SqlData → `getCustomers`
- **totalcount**: Count of returned rows.  
- **rows[].TRDR**: Customer id (primary key).  
- **rows[].CODE**: External/readable customer code.  
- **rows[].NAME**: Name.  
- **rows[].EMAIL**: Email (**redacted**).  
- **rows[].ADDRESS/CITY/ZIP/COUNTRY/PHONE1**: Contact info.

### SqlData → `getItems`
- **totalcount**: Count of items.  
- **rows[].MTRL**: Item id (primary key).  
- **rows[].CODE**: Item code.  
- **rows[].DESC**: Item description/name.  
- **rows[].RETAILPRICE**: Price (string/number depending on config).  
- **Category fields**: `COMMERCATEGORY`, `COMMECATEGORY NAME`.  
- **Subcategory fields**: `SUBCATEGORY`, `SUBMECATEGORY NAME`.  
- **Barcode/SKU**: `BARCODE`, `SKU`.  
- **Colour/Size**: `COLOUR`, `COLOUR NAME`, `SIZE`, `SIZE NAME`.  
- **Brand**: `BRAND CODE`, `BRAND NAME`.  
- **Stock**: `Stock QTY` (maps to `QTY1` in outputs list).  
- **Possible additional outputs** (present in schema; may not appear in sample): `UTBL03` (+ name), `MTRSEASON` (+ name), `CCCSOCYLODES`, `REMARKS`, `CCCSOCYRE2`, `BOOL01`, `CCCSOCYSHDES`.

### setData → `CUSTOMER` / `SALDOC` Responses
- **success**: Operation status.  
- **id**: Newly created/affected record id (e.g., `TRDR` for CUSTOMER, document id for SALDOC).

---

## Security & Sharing
- **Do not** embed real credentials, serials, or `clientID` values in code or docs. Use a secrets vault and environment variables.  
- Rotate credentials if this document ever leaves your trusted environment.  
- Restrict network access to `https://ptkids.oncloud.gr/s1services` to known IPs where possible.

---

**© P.T. KIDS & TEEN’S FURNITURE LTD (Redacted Copy)**  
**Compiled for secure internal use only.**
