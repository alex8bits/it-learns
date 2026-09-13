---
description: Синхронизировать Postman-коллекцию с текущими роутами приложения
---

# Command: Sync Postman Collection with Application Routes

## Goal
Ensure the API documentation is complete by comparing the application's current routes with an existing Postman collection and adding any missing endpoints to the documentation at `.claude/docs/docs.postman_collection.json` (or the path given in $ARGUMENTS).

If the collection file does not exist, say so and stop — do not create a new collection from scratch unless the user asks for it.

## Inputs
- Access to the application's **source code** to detect existing API routes (`routes/api.php`, under the `/api/v1` prefix).
- Existing **API documentation files** in the repository (if present).

## Steps

1. **Load the Postman Collection**
   - Read the provided Postman collection JSON file.
   - Extract all endpoints including:
     - HTTP method
     - route/path
     - query parameters
     - request body schema (if available)
     - headers

2. **Scan Application Routes**
   - Detect all API routes from the application source code.
   - Prefer `php artisan route:list --json` (filter to API routes); otherwise parse the route files directly.
   - Capture:
     - HTTP method
     - route/path
     - parameters
     - request/response schemas if available (FormRequest rules, Resource classes).

3. **Compare Endpoints**
   - Match endpoints by **HTTP method + route path**.
   - Identify:
     - Routes present in the application but **missing in the Postman collection**.
     - Routes present in the Postman collection but **missing in the documentation**.

4. **Update Documentation**
   - For each missing endpoint:
     - Add a documentation section using the existing documentation style.
     - Include:
       - Method
       - Path
       - Description (infer from route/controller name if needed)
       - Parameters
       - Example request
       - Example response (if possible).

5. **Preserve Structure**
   - Do not rewrite existing documentation.
   - Only **append missing endpoints** in the appropriate sections.

6. **Output**
   - Update the documentation files directly.
   - Provide a summary of:
     - Endpoints added
     - Endpoints skipped (already documented)
     - Any routes that could not be parsed.

## Rules
- Do not remove or modify existing documented endpoints.
- Follow the existing documentation format and file structure.
- If multiple API versions exist (e.g., `/v1/`, later `/v2/`), treat them separately. Currently only `/api/v1` exists.
- Normalize paths before comparison (remove trailing slashes, normalize parameters).

## Expected Result
The documentation will contain **all currently implemented API routes**, with missing endpoints automatically added based on the application's route definitions.
