# GoodData Writer Migrate

[![Build Status](https://travis-ci.com/keboola/app-gooddata-writer-migrate.svg?branch=master)](https://travis-ci.com/keboola/app-gooddata-writer-migrate)

Application for migrating GoodData writers between projects and regions.  
It migrates all GoodData writers in the source project into the project where the application is executed.

- It migrates the whole GoodData writer configuration. IDs of configurations are preserved.
- It clones the associated GoodData projects with all data and settings (Dashboards, Reports, Metrics, Data Sets).
- It doesn't migrate the GoodData project users.
- It doesn't migrate the GoodData writers' source data. [Project Restore](https://github.com/keboola/app-project-restore) should be used for data migration.
- The application requires a token with `admin` privileges.

## Development

Clone this repository and initialize the workspace with the following command:

```
git clone https://github.com/keboola/app-gooddata-writer-migrate
cd app-gooddata-writer-migrate
docker-compose build
docker-compose run --rm dev composer install --no-scripts
```

Run the test suite using this command:

```
docker-compose run --rm dev composer tests
```

# Integration

For information about deployment and integration with Keboola, please refer to the [deployment section of the developers' documentation](https://developers.keboola.com/extend/component/deployment/)