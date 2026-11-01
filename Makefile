export APP_UID ?= $(shell id -u)
export APP_GID ?= $(shell id -g)

COMPOSE_DEV := docker compose -f compose.yaml -f compose.dev.yaml

.DEFAULT_GOAL := help
.PHONY: help up dev down logs shell test ps qa cs-fix

help: ## Список команд
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "}; {printf "  \033[0;32m%-8s\033[0m %s\n", $$1, $$2}'

up: ## Production-like запуск (код в образе, подготовка при запуске)
	@.docker/scripts/up.sh

dev: ## Development: bind mount ./app, Xdebug
	@.docker/scripts/dev.sh

down: ## Остановить контейнеры (volumes с данными сохраняются)
	@$(COMPOSE_DEV) down --remove-orphans

logs: ## Логи всех сервисов (make logs s=worker — одного сервиса)
	@$(COMPOSE_DEV) logs -f --tail=100 $(s)

shell: ## Консоль PHP-контейнера app
	@$(COMPOSE_DEV) exec app sh

test: ## PHPUnit в dev-контейнере (make test a="--testsuite Unit")
	@.docker/scripts/test.sh $(a)

ps: ## Состояние контейнеров
	@$(COMPOSE_DEV) ps

qa: ## PHP CS Fixer (check), PHPStan, Deptrac
	@.docker/scripts/qa.sh

cs-fix: ## Исправить code style
	@.docker/scripts/qa.sh fix
