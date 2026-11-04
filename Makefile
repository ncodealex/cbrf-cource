export APP_UID ?= $(shell id -u)
export APP_GID ?= $(shell id -g)

COMPOSE_DEV := docker compose -f compose.yaml -f compose.dev.yaml

.DEFAULT_GOAL := help
.PHONY: help up dev down logs shell test test-startup ps qa cs-fix

help: ## Список команд
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "}; {printf "  \033[0;32m%-13s\033[0m %s\n", $$1, $$2}'

up: ## Production-like запуск (код в образе, подготовка при запуске)
	@docker compose -f compose.yaml up --build -d --wait --wait-timeout 600

dev: ## Development: bind mount ./app, Xdebug
	@$(COMPOSE_DEV) up --build -d --wait --wait-timeout 600

down: ## Остановить контейнеры (volumes с данными сохраняются)
	@$(COMPOSE_DEV) down --remove-orphans

logs: ## Логи всех сервисов (make logs s=worker — одного сервиса)
	@$(COMPOSE_DEV) logs -f --tail=100 $(s)

shell: ## Консоль PHP-контейнера app
	@$(COMPOSE_DEV) run --rm --no-deps app sh

test: ## PHPUnit в изолированном окружении (make test a="--testsuite Unit")
	@.docker/scripts/test.sh $(a)

test-startup: ## Холодный запуск, миграции и перезапуск Worker
	@.docker/scripts/test-startup.sh

ps: ## Состояние контейнеров
	@$(COMPOSE_DEV) ps

qa: ## PHP CS Fixer (check), PHPStan, Deptrac
	@$(COMPOSE_DEV) run --build --rm --no-deps qa

cs-fix: ## Исправить code style
	@$(COMPOSE_DEV) run --build --rm --no-deps qa app-qa fix
