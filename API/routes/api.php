<?php

use App\Http\Controllers\Api\Admin\AccountInviteIndexController;
use App\Http\Controllers\Api\Admin\AccountInviteIssueLinkController;
use App\Http\Controllers\Api\Admin\AccountInviteRevokeController;
use App\Http\Controllers\Api\Admin\AccountInviteStoreController;
use App\Http\Controllers\Api\Admin\AccountInviteVerifyOutOfBandController;
use App\Http\Controllers\Api\Admin\MigrantSigningLedgerController;
use App\Http\Controllers\Api\Admin\SigningLedgerController;
use App\Http\Controllers\Api\Admin\UserCurpUpdateOptionsController;
use App\Http\Controllers\Api\Admin\UserCurpUpdateVerifyController;
use App\Http\Controllers\Api\Admin\UserDepartmentsUpdateController;
use App\Http\Controllers\Api\Admin\UserIndexController;
use App\Http\Controllers\Api\Admin\UserRecoveryOptionsController;
use App\Http\Controllers\Api\Admin\UserRecoveryVerifyController;
use App\Http\Controllers\Api\Admin\UserRoleUpdateOptionsController;
use App\Http\Controllers\Api\Admin\UserRoleUpdateVerifyController;
use App\Http\Controllers\Api\Admin\UserStatusUpdateOptionsController;
use App\Http\Controllers\Api\Admin\UserStatusUpdateVerifyController;
use App\Http\Controllers\Api\Admin\VerificationPackageSigningKeyController;
use App\Http\Controllers\Api\Admin\VerificationPackageSigningKeyRotationOptionsController;
use App\Http\Controllers\Api\Admin\VerificationPackageSigningKeyRotationVerifyController;
use App\Http\Controllers\Api\Audit\AuditEventIndexController;
use App\Http\Controllers\Api\Auth\AccountInvitePreviewController;
use App\Http\Controllers\Api\Auth\AccountInviteRedeemController;
use App\Http\Controllers\Api\Auth\AdminAuthorizationCheckController;
use App\Http\Controllers\Api\Auth\CsrfTokenController;
use App\Http\Controllers\Api\Auth\CurrentUserController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\PasswordResetCompleteController;
use App\Http\Controllers\Api\Auth\TotpEnrollmentOptionsController;
use App\Http\Controllers\Api\Auth\TotpEnrollmentVerifyController;
use App\Http\Controllers\Api\Auth\TotpLoginController;
use App\Http\Controllers\Api\Auth\WebauthnCredentialDeleteController;
use App\Http\Controllers\Api\Auth\WebauthnCredentialListController;
use App\Http\Controllers\Api\Auth\WebauthnLoginOptionsController;
use App\Http\Controllers\Api\Auth\WebauthnLoginVerifyController;
use App\Http\Controllers\Api\Auth\WebauthnRegistrationOptionsController;
use App\Http\Controllers\Api\Auth\WebauthnRegistrationVerifyController;
use App\Http\Controllers\Api\Documents\DocumentDeleteOptionsController;
use App\Http\Controllers\Api\Documents\DocumentDeleteVerifyController;
use App\Http\Controllers\Api\Documents\DocumentDownloadController;
use App\Http\Controllers\Api\Documents\DocumentIndexController;
use App\Http\Controllers\Api\Documents\DocumentRevisionUpdateOptionsController;
use App\Http\Controllers\Api\Documents\DocumentRevisionUpdateVerifyController;
use App\Http\Controllers\Api\Documents\DocumentShowController;
use App\Http\Controllers\Api\Documents\DocumentSignaturePolicySignerOptionsController;
use App\Http\Controllers\Api\Documents\DocumentSignaturePolicyUpdateController;
use App\Http\Controllers\Api\Documents\DocumentSignaturePresentationController;
use App\Http\Controllers\Api\Documents\DocumentSignOptionsController;
use App\Http\Controllers\Api\Documents\DocumentSignVerifyController;
use App\Http\Controllers\Api\Documents\DocumentStoreController;
use App\Http\Controllers\Api\Documents\DocumentVerificationBundleController;
use App\Http\Controllers\Api\Documents\DocumentVerificationController;
use App\Http\Controllers\Api\Documents\DocumentVerificationPackageController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\Registry\MigrantArcoAccessDocumentController;
use App\Http\Controllers\Api\Registry\MigrantArcoController;
use App\Http\Controllers\Api\Registry\MigrantArcoCreateOptionsController;
use App\Http\Controllers\Api\Registry\MigrantArcoCreateVerifyController;
use App\Http\Controllers\Api\Registry\MigrantArcoDecisionOptionsController;
use App\Http\Controllers\Api\Registry\MigrantArcoDecisionVerifyController;
use App\Http\Controllers\Api\Registry\MigrantRegistryApprovalOptionsController;
use App\Http\Controllers\Api\Registry\MigrantRegistryApprovalVerifyController;
use App\Http\Controllers\Api\Registry\MigrantRegistryBulkApprovalOptionsController;
use App\Http\Controllers\Api\Registry\MigrantRegistryBulkApprovalVerifyController;
use App\Http\Controllers\Api\Registry\MigrantRegistryController;
use App\Http\Controllers\Api\Registry\MigrantRegistryDocumentController;
use App\Http\Controllers\Api\Registry\MigrantRegistryDocumentDownloadOptionsController;
use App\Http\Controllers\Api\Registry\MigrantRegistryDocumentDownloadVerifyController;
use App\Http\Controllers\Api\Registry\MigrantRegistryPdfDownloadOptionsController;
use App\Http\Controllers\Api\Registry\MigrantRegistryPdfDownloadVerifyController;
use App\Http\Controllers\Api\Registry\MigrantRegistryReviewOptionsController;
use App\Http\Controllers\Api\Registry\MigrantRegistryReviewReturnController;
use App\Http\Controllers\Api\Registry\MigrantRegistryReviewVerifyController;
use App\Http\Controllers\Api\SecurityChallengeCancelController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::middleware('web')->group(function (): void {
    Route::get('/csrf-token', CsrfTokenController::class);
    Route::post('/login', LoginController::class);
    Route::post('/login/totp', TotpLoginController::class);
    Route::post('/password-reset/complete', PasswordResetCompleteController::class)->middleware('throttle:10,1');
    Route::post('/totp/enroll/options', TotpEnrollmentOptionsController::class)->middleware(['auth', 'requireActiveAccount']);
    Route::post('/totp/enroll/verify', TotpEnrollmentVerifyController::class)->middleware(['auth', 'requireActiveAccount']);
    Route::post('/webauthn/login/options', WebauthnLoginOptionsController::class);
    Route::post('/webauthn/login/verify', WebauthnLoginVerifyController::class);
    Route::get('/invites/preview', AccountInvitePreviewController::class)->middleware('throttle:30,1');
    Route::post('/invites/redeem', AccountInviteRedeemController::class)->middleware('throttle:10,1');
    Route::post('/logout', LogoutController::class);
    Route::get('/me', CurrentUserController::class)->middleware(['auth', 'requireActiveAccount']);
    Route::get('/webauthn/credentials', WebauthnCredentialListController::class)->middleware(['auth', 'requireActiveAccount']);
    Route::delete('/webauthn/credentials/{credentialId}', WebauthnCredentialDeleteController::class)->middleware(['auth', 'requireActiveAccount']);
    Route::post('/webauthn/register/options', WebauthnRegistrationOptionsController::class)->middleware(['auth', 'requireActiveAccount']);
    Route::post('/webauthn/register/verify', WebauthnRegistrationVerifyController::class)->middleware(['auth', 'requireActiveAccount']);
    Route::post('/security-challenges/{intent}/cancel', SecurityChallengeCancelController::class)->middleware(['auth', 'requireActiveAccount']);

    Route::middleware(['auth', 'requireActiveAccount', 'requireDepartment', 'requireRole:admin'])->group(function (): void {
        Route::get('/admin/authorization-check', AdminAuthorizationCheckController::class);
        Route::get('/admin/users', UserIndexController::class);
        Route::post('/admin/users/{user}/curp/options', UserCurpUpdateOptionsController::class)->middleware('throttle:30,1');
        Route::post('/admin/users/{user}/curp/verify', UserCurpUpdateVerifyController::class)->middleware('throttle:30,1');
        Route::get('/admin/signing-ledger', SigningLedgerController::class);
        Route::get('/admin/migrant-signing-ledger', MigrantSigningLedgerController::class);
        Route::post('/admin/users/{user}/recovery/options', UserRecoveryOptionsController::class)->middleware('throttle:30,1');
        Route::post('/admin/users/{user}/recovery/verify', UserRecoveryVerifyController::class)->middleware('throttle:30,1');
        Route::post('/admin/users/{user}/role/options', UserRoleUpdateOptionsController::class)->middleware('throttle:30,1');
        Route::post('/admin/users/{user}/role/verify', UserRoleUpdateVerifyController::class)->middleware('throttle:30,1');
        Route::post('/admin/users/{user}/status/options', UserStatusUpdateOptionsController::class)->middleware('throttle:30,1');
        Route::post('/admin/users/{user}/status/verify', UserStatusUpdateVerifyController::class)->middleware('throttle:30,1');
        Route::put('/admin/users/{user}/departments', UserDepartmentsUpdateController::class)->middleware(['requireSecurityEnrollment', 'throttle:30,1']);
        Route::get('/admin/verification-package-signing-key', VerificationPackageSigningKeyController::class);
        Route::post('/admin/verification-package-signing-key/rotation/options', VerificationPackageSigningKeyRotationOptionsController::class)->middleware('throttle:10,1');
        Route::post('/admin/verification-package-signing-key/rotation/verify', VerificationPackageSigningKeyRotationVerifyController::class)->middleware('throttle:10,1');
        Route::get('/audit-events', AuditEventIndexController::class);
        Route::get('/documents/signature-policy/signer-options', DocumentSignaturePolicySignerOptionsController::class)
            ->middleware('requireSecurityEnrollment');
        Route::put('/documents/{document}/revisions/{revision}/signature-policy', DocumentSignaturePolicyUpdateController::class)
            ->middleware('requireSecurityEnrollment');
    });

    Route::middleware(['auth', 'requireActiveAccount', 'requireSecurityEnrollment', 'requireDepartment', 'requireRole:admin,coordinator,non_coordinator,volunteer'])->group(function (): void {
        Route::post('/documents', DocumentStoreController::class);
    });

    Route::middleware(['auth', 'requireActiveAccount', 'requireSecurityEnrollment', 'requireDepartment', 'requireRole:admin,coordinator,non_coordinator'])->group(function (): void {
        Route::get('/documents', DocumentIndexController::class);
        Route::get('/documents/{document}', DocumentShowController::class);
        Route::get('/documents/{document}/download', DocumentDownloadController::class);
        Route::get('/documents/{document}/revisions/{revision}/download', DocumentDownloadController::class);
        Route::get('/documents/{document}/signed-pdf', DocumentSignaturePresentationController::class);
        Route::get('/documents/{document}/revisions/{revision}/signed-pdf', DocumentSignaturePresentationController::class);
        Route::get('/documents/{document}/verification', DocumentVerificationController::class);
        Route::get('/documents/{document}/verification-bundle', DocumentVerificationBundleController::class);
        Route::get('/documents/{document}/revisions/{revision}/verification-bundle', DocumentVerificationBundleController::class);
        Route::get('/documents/{document}/verification-package', DocumentVerificationPackageController::class);
        Route::get('/documents/{document}/revisions/{revision}/verification-package', DocumentVerificationPackageController::class);
    });

    Route::middleware(['auth', 'requireActiveAccount', 'requireSecurityEnrollment', 'requireDepartment', 'requireRole:admin,coordinator'])->group(function (): void {
        Route::get('/admin/invites', AccountInviteIndexController::class);
        Route::post('/documents/{document}/revisions/options', DocumentRevisionUpdateOptionsController::class);
        Route::post('/documents/{document}/revisions/verify', DocumentRevisionUpdateVerifyController::class);
        Route::post('/documents/{document}/revisions/{revision}/sign/options', DocumentSignOptionsController::class);
        Route::post('/documents/{document}/revisions/{revision}/sign/verify', DocumentSignVerifyController::class);
        Route::post('/documents/{document}/sign/options', DocumentSignOptionsController::class);
        Route::post('/documents/{document}/sign/verify', DocumentSignVerifyController::class);
        Route::post('/admin/invites', AccountInviteStoreController::class)->middleware('throttle:30,1');
        Route::post('/admin/invites/{invite}/verify-out-of-band', AccountInviteVerifyOutOfBandController::class)->middleware('throttle:30,1');
        Route::post('/admin/invites/{invite}/issue-link', AccountInviteIssueLinkController::class)->middleware('throttle:30,1');
        Route::post('/admin/invites/{invite}/revoke', AccountInviteRevokeController::class)->middleware('throttle:30,1');
    });

    Route::middleware(['auth', 'requireActiveAccount', 'requireDepartment', 'requireRole:admin'])->group(function (): void {
        Route::post('/documents/{document}/delete/options', DocumentDeleteOptionsController::class);
        Route::post('/documents/{document}/delete/verify', DocumentDeleteVerifyController::class);
    });

    Route::middleware(['auth', 'requireActiveAccount', 'requireSecurityEnrollment', 'requireDepartment', 'requireRole:admin,coordinator,non_coordinator,volunteer'])->prefix('registry/migrants')->group(function (): void {
        Route::get('/', [MigrantRegistryController::class, 'index']);
        Route::post('/', [MigrantRegistryController::class, 'store']);
        Route::get('/corrections', [MigrantRegistryController::class, 'corrections']);
        Route::get('/questionnaires/current', [MigrantRegistryController::class, 'questionnaire']);
        Route::get('/drafts', [MigrantRegistryController::class, 'drafts']);
        Route::post('/drafts', [MigrantRegistryController::class, 'storeDraft']);
        Route::patch('/drafts/{migrantRegistryEntry}', [MigrantRegistryController::class, 'updateDraft']);
        Route::delete('/drafts/{migrantRegistryEntry}', [MigrantRegistryController::class, 'discardDraft']);
        Route::post('/drafts/{migrantRegistryEntry}/submit', [MigrantRegistryController::class, 'submitDraft']);

        Route::prefix('arco')->middleware(['requireFeature:arco', 'requireRole:admin,coordinator,non_coordinator'])->group(function (): void {
            Route::get('/', [MigrantArcoController::class, 'index']);
            Route::post('/create/options', MigrantArcoCreateOptionsController::class);
            Route::post('/create/verify', MigrantArcoCreateVerifyController::class);
            Route::get('/{migrantArcoRequest}', [MigrantArcoController::class, 'show']);
            Route::get('/{migrantArcoRequest}/access-document', MigrantArcoAccessDocumentController::class);
            Route::post('/{migrantArcoRequest}/coordinator-decision/options', [MigrantArcoDecisionOptionsController::class, 'coordinator']);
            Route::post('/{migrantArcoRequest}/coordinator-decision/verify', [MigrantArcoDecisionVerifyController::class, 'coordinator']);
            Route::post('/{migrantArcoRequest}/admin-decision/options', [MigrantArcoDecisionOptionsController::class, 'admin']);
            Route::post('/{migrantArcoRequest}/admin-decision/verify', [MigrantArcoDecisionVerifyController::class, 'admin']);
        });

        Route::prefix('{migrantRegistryEntry}/documents')->middleware('requireFeature:migrant_documents')->group(function (): void {
            Route::post('/', [MigrantRegistryDocumentController::class, 'store']);
            Route::delete('/{migrantRegistryDocument}', [MigrantRegistryDocumentController::class, 'destroy']);

            Route::middleware('requireRole:admin,coordinator,non_coordinator')->group(function (): void {
                Route::get('/', [MigrantRegistryDocumentController::class, 'index']);
            });

            // Downloads use POST for the passkey challenge but change nothing.
            Route::middleware('requireRole:admin,coordinator,non_coordinator')->withoutMiddleware('requireDepartment')->group(function (): void {
                Route::post('/{migrantRegistryDocument}/download/options', MigrantRegistryDocumentDownloadOptionsController::class);
                Route::post('/{migrantRegistryDocument}/download/verify', MigrantRegistryDocumentDownloadVerifyController::class);
            });
        });

        Route::middleware('requireRole:admin,coordinator,non_coordinator')->group(function (): void {
            Route::get('/pending-review', [MigrantRegistryController::class, 'pendingReview']);
            Route::post('/{migrantRegistryEntry}/review/options', MigrantRegistryReviewOptionsController::class);
            Route::post('/{migrantRegistryEntry}/review/verify', MigrantRegistryReviewVerifyController::class);
            Route::post('/{migrantRegistryEntry}/review/return', MigrantRegistryReviewReturnController::class);
        });

        Route::middleware('requireRole:admin,coordinator')->group(function (): void {
            Route::get('/pending-approval', [MigrantRegistryController::class, 'pendingApproval']);
            Route::post('/bulk-approval/options', MigrantRegistryBulkApprovalOptionsController::class);
            Route::post('/bulk-approval/verify', MigrantRegistryBulkApprovalVerifyController::class);
            Route::post('/{migrantRegistryEntry}/approval/options', MigrantRegistryApprovalOptionsController::class);
            Route::post('/{migrantRegistryEntry}/approval/verify', MigrantRegistryApprovalVerifyController::class);
        });

        Route::middleware('requireRole:admin')->withoutMiddleware('requireDepartment')->group(function (): void {
            Route::post('/{migrantRegistryEntry}/pdf/options', MigrantRegistryPdfDownloadOptionsController::class);
            Route::post('/{migrantRegistryEntry}/pdf/verify', MigrantRegistryPdfDownloadVerifyController::class);
        });

        Route::delete('/{migrantRegistryEntry}', [MigrantRegistryController::class, 'destroy'])
            ->middleware('requireRole:admin');

        Route::get('/{migrantRegistryEntry}', [MigrantRegistryController::class, 'show']);
        Route::patch('/{migrantRegistryEntry}', [MigrantRegistryController::class, 'update']);
    });
});
