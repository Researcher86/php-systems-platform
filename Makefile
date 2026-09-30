.PHONY: up down build shell install test analyse format format-check run demo

# CI has no terminal to attach to (GitHub Actions sets CI=true), so exec runs
# without one there; locally it keeps the TTY for colours and interactive use.
TTY := $(if $(CI),-T,)

up:
	docker compose up -d

down:
	docker compose down

build:
	docker compose build

shell: up
	docker compose exec $(TTY) php bash

install: up
	docker compose exec $(TTY) php composer install

test: up
	docker compose exec $(TTY) php composer test

analyse: up
	docker compose exec $(TTY) php composer analyse

format: up
	docker compose exec $(TTY) php composer format

format-check: up
	docker compose exec $(TTY) php composer format:check

run: up
	docker compose exec $(TTY) php php bin/platform.php $(ARGS)

demo: up
	docker compose exec $(TTY) php php bin/platform.php demo