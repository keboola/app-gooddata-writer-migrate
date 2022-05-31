# GoodData Writer Migrate

[![Build Status](https://travis-ci.com/keboola/app-gooddata-writer-migrate.svg?branch=master)](https://travis-ci.com/keboola/app-gooddata-writer-migrate)

Application for migrating GoodData writers between project's and regions.
It migrates all GoodData writers in source project into project where the application is executed.

- It migrates whole GoodData writer configuration. Ids of configurations are preserved.
- It clones the associated GoodData projects with all data and settings (Dashboards, reports, metrics, data sets).
- It doesn't migrate GoodData project users
- It doesn't migrate GoodData writers source data. [Project restore](https://github.com/keboola/app-project-restore) should be used for data migration.
- Application requires token with `admin` privileges


## Development
 
Clone this repository and init the workspace with following command:

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

For information about deployment and integration with KBC, please refer to the [deployment section of developers documentation](https://developers.keboola.com/extend/component/deployment/) 

## License

MIT licensed, see [LICENSE](./LICENSE) file.
