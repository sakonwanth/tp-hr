# AUDIT_15_FULL_REGRESSION_CHECKLIST.md

Date: 2026-04-30  
Project: `tp-hr`

Use this checklist for final full regression after fixes.

## 1) Login

- [ ] `/login.php` load
- [ ] username/password login success
- [ ] invalid password error
- [ ] inactive user denied
- [ ] logout clears session

## 2) Dashboard

- [ ] `/index.php` employee widgets load
- [ ] HR dashboard cards load for HR role
- [ ] quick action links route correctly

## 3) All forms

- [ ] leave request form submit
- [ ] dayoff request form submit
- [ ] certificate request form submit
- [ ] profile section forms submit
- [ ] HR settings forms submit
- [ ] employee create/edit forms submit
- [ ] API key create form submit

## 4) All CRUD actions

- [ ] profile emergency contact add/update/delete
- [ ] family add/update/delete
- [ ] education add/update/delete
- [ ] work history add/update/delete
- [ ] employee activate/deactivate/update
- [ ] template create/update/delete

## 5) All approval flows

- [ ] leave approve/reject
- [ ] dayoff approve/reject
- [ ] outside-attendance approve/reject
- [ ] attendance-adjustment approve/reject
- [ ] document process/complete/reject

## 6) All reports

- [ ] attendance report mode
- [ ] leave report mode
- [ ] leave-summary mode
- [ ] daily report mode

## 7) All exports

- [ ] reports CSV export (POST + CSRF only)
- [ ] payslip download/export
- [ ] certificate print/PDF flow

## 8) All uploads

- [ ] leave document upload valid/invalid
- [ ] certificate attachment upload valid/invalid
- [ ] HR completion document upload valid/invalid
- [ ] template signature/logo/seal upload valid/invalid

## 9) All notifications

- [ ] new leave LINE event path
- [ ] leave approve/reject LINE event path
- [ ] planned-late requested/cancelled/confirmed LINE path
- [ ] failure path logs when CRM bridge unavailable

## 10) All role-based pages

- [ ] guest denied on protected pages
- [ ] employee cannot access HR pages
- [ ] HR can access HR non-CEO pages
- [ ] CEO-only pages blocked for non-CEO HR users

## 11) All cross-system flows

- [ ] SSO redirect/login continuity with CRM
- [ ] line token login via `cross_domain_tokens`
- [ ] checkin photo URL/proxy render behavior
- [ ] shared payroll tables readable from HR

## 12) All database write actions

- [ ] attendance write paths
- [ ] leave write paths
- [ ] document write paths
- [ ] profile write paths
- [ ] employee/settings write paths
- [ ] API key write paths

## 13) All database read actions

- [ ] employee summary reads
- [ ] attendance history reads
- [ ] leave/calendar reads
- [ ] report aggregation reads
- [ ] verify-document public reads

## 14) All API calls

- [ ] `/api/*` action matrix (success/failure)
- [ ] `/api/v1/*` scope matrix
- [ ] invalid scope/method handling
- [ ] rate-limit behavior
- [ ] API request logs generated

## Mandatory final gates

- [ ] `scripts/production_preflight.php --strict` PASS
- [ ] static contract checks PASS
- [ ] guest E2E suite PASS
- [ ] authenticated role E2E suite PASS
- [ ] no open Critical/High issues

