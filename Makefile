SHELL := /bin/bash

PHP ?= php
COMPOSER ?= composer
NPM ?= npm
ARTISAN := $(PHP) artisan

.DEFAULT_GOAL := help

.PHONY: help install hooks dev test test-filter analyse quality coverage pint pint-fix build migrate migrate-force assets clear-cache optimize route-list deploy-check deploy

help: ## Affiche les commandes disponibles
	@awk 'BEGIN {FS = ":.*##"; printf "\nCommandes JP2 Hub:\n"} /^[a-zA-Z0-9_-]+:.*##/ {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

install: ## Installe le projet local complet
	$(COMPOSER) install
	@if [ ! -f .env ]; then cp .env.example .env; fi
	$(ARTISAN) key:generate --ansi
	$(ARTISAN) migrate --force
	$(NPM) install
	$(NPM) run build
	$(ARTISAN) crm:publish-static-assets --force --clean
	$(ARTISAN) crm:publish-module-assets --force
	$(MAKE) hooks

hooks: ## Active les hooks Git versionnes du depot
	bash scripts/install-git-hooks.sh

dev: ## Lance le serveur Laravel, Vite, Horizon et les logs
	$(COMPOSER) dev

test: ## Lance la suite PHPUnit Laravel
	$(COMPOSER) test

test-filter: ## Lance un test cible: make test-filter FILTER=NomDuTest
	@if [ -z "$(FILTER)" ]; then echo "Usage: make test-filter FILTER=NomDuTest"; exit 1; fi
	$(ARTISAN) test --filter="$(FILTER)"

analyse: ## Lance PHPStan/Larastan
	$(COMPOSER) analyse

quality: ## Lance Pint, PHPStan et les tests Laravel
	$(COMPOSER) quality

coverage: ## Lance les tests avec seuil de couverture
	$(COMPOSER) test:coverage

pint: ## Verifie le format Laravel Pint
	$(COMPOSER) pint

pint-fix: ## Corrige le format Laravel Pint
	$(COMPOSER) pint:fix

build: ## Compile les assets Vite
	$(NPM) run build

migrate: ## Execute les migrations locales
	$(ARTISAN) migrate

migrate-force: ## Execute les migrations avec --force
	$(ARTISAN) migrate --force

assets: ## Publie les assets statiques et modules HUB
	$(ARTISAN) crm:publish-static-assets --force --clean
	$(ARTISAN) crm:publish-module-assets --force

clear-cache: ## Vide les caches Laravel
	$(ARTISAN) optimize:clear

optimize: ## Reconstruit les caches Laravel de production
	$(ARTISAN) optimize
	$(ARTISAN) view:cache

route-list: ## Liste les routes HUB principales
	$(ARTISAN) route:list --path=api

deploy-check: ## Verifie le projet avant deploiement
	$(COMPOSER) quality
	$(NPM) run build
	$(ARTISAN) crm:publish-static-assets --force --clean
	$(ARTISAN) crm:publish-module-assets --force
	$(ARTISAN) test --filter=CrmModuleManifestTest

deploy: ## Deploie via SSH avec CRM_DEPLOY_* configurees
	bash scripts/deploy-planethoster.sh
