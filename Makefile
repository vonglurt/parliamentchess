# Parliament Chess
#
#   make up             the server in the background: http://localhost:8080 (PORT=8080)
#   make run            the same as make up
#   make down           stop it
#   make status         is it up, and where
#   make logs           follow its log
#   make test           the rule tests, then two browsers playing each other through a server of their own
#   make test-rules     45 rule tests (FIDE perft counts and every election outcome)
#   make test-browsers  the two-browser test alone (URL=http://127.0.0.1:8080 to test a running server)
#   make export         the lab report from the shared doc's HTML export (EXPORT=path/to/export.html)
#
# Needs PHP 8.2+ with pdo_sqlite (for the opening book; the game runs without it).

PORT ?= 8080
RUN  := .run
PID  := $(RUN)/server.pid
PHP  ?= php

.PHONY: help up run down status logs test test-rules test-browsers export

help:
	@sed -n '3,14p' Makefile

up:
	@mkdir -p $(RUN)
	@if [ -f $(PID) ] && kill -0 $$(cat $(PID)) 2>/dev/null; then \
		echo "already up: http://localhost:$(PORT) (pid $$(cat $(PID)))"; \
	elif curl -s -o /dev/null http://127.0.0.1:$(PORT)/ 2>/dev/null; then \
		echo "port $(PORT) is taken by another program: make up PORT=8081"; exit 1; \
	else \
		nohup sh bin/serve $(PORT) > $(RUN)/server.log 2>&1 & echo $$! > $(PID); \
		for i in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15; do curl -s -o /dev/null http://127.0.0.1:$(PORT)/ && break; sleep 0.2; done; \
		if kill -0 $$(cat $(PID)) 2>/dev/null && curl -s -o /dev/null http://127.0.0.1:$(PORT)/; then \
			echo "up: http://localhost:$(PORT)  (pid $$(cat $(PID)), log $(RUN)/server.log; make down to stop)"; \
		else echo "it did not start:"; cat $(RUN)/server.log; rm -f $(PID); exit 1; fi; \
	fi

run: up

down:
	@if [ -f $(PID) ]; then \
		P=$$(cat $(PID)); pkill -TERM -P $$P 2>/dev/null; kill $$P 2>/dev/null; rm -f $(PID); echo "down (pid $$P)"; \
	else echo "not running (no $(PID))"; fi

status:
	@if [ -f $(PID) ] && kill -0 $$(cat $(PID)) 2>/dev/null; then echo "up: http://localhost:$(PORT) (pid $$(cat $(PID)))"; else echo "down"; fi

logs:
	@tail -f $(RUN)/server.log

test: test-rules test-browsers

test-rules:
	@sh bin/php bin/test.php

test-browsers:
	@$(PHP) bin/test-browsers.php $(if $(URL),--url $(URL),)

export:
	@test -n "$(EXPORT)" || { echo "make export EXPORT=path/to/the-doc-export.html"; exit 2; }
	python3 tools/report2docs.py "$(EXPORT)" docs/parliament-chess-lab-report.md docs/parliament-chess-lab-report.html
