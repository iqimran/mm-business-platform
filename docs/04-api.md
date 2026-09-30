# API Conventions

## Versioning
All APIs use `/api/v1/...`.

## REST
Use standard REST conventions where appropriate:
- GET /api/v1/cars
- POST /api/v1/cars
- GET /api/v1/cars/{id}
- PUT /api/v1/cars/{id}
- DELETE /api/v1/cars/{id}

Business actions may use explicit endpoints:
- POST /api/v1/cars/{id}/sell
- POST /api/v1/car-sales/{id}/payments
- POST /api/v1/cars/import

Do not force business actions into CRUD when that harms domain clarity.

## Response contract

Success:
```json
{
  "success": true,
  "message": "Operation completed successfully.",
  "data": {}
}
```

Validation failure:
```json
{
  "success": false,
  "message": "Validation failed.",
  "errors": {}
}
```

Never expose internal stack traces or database errors.

## API documentation
Maintain OpenAPI documentation.
