.PHONY: up down build shell install test analyse format format-check run demo

up:
	docker compose up -d

down:
	docker compose down

build:
	docker compose build

shell: up
	docker compose exec php bash

install: up
	docker compose exec php composer install

test: up
	docker compose exec php composer test

analyse: up
	docker compose exec php composer analyse

format: up
	docker compose exec php composer format

format-check: up
	docker compose exec php composer format:check

run: up
	docker compose exec php php bin/platform.php $(ARGS)

demo: up
	docker compose exec php php bin/platform.php demo