<?php

namespace Tests\Unit\Views;

use Tests\TestCase;

class DashboardTeacherParentExportButtonTest extends TestCase
{
    public function testTeacherDashboardProvidesProviderOnlyParentPdfDownloadButton(): void
    {
        $view = file_get_contents(VIEWPATH . 'pages/dashboard_teacher.php');
        $teacherScript = file_get_contents(FCPATH . 'assets/js/pages/dashboard_teacher.js');
        $httpClient = file_get_contents(FCPATH . 'assets/js/http/dashboard_http_client.js');
        $routes = file_get_contents(APPPATH . 'config/routes.php');

        $this->assertStringContainsString('id="dashboard-teacher-parent-export"', $view);
        $this->assertStringContainsString('PDF für Eltern herunterladen', $view);
        $this->assertStringContainsString('onParentExportClick', $teacherScript);
        $this->assertStringContainsString('downloadProviderParentAppointmentsExport(filters)', $teacherScript);
        $this->assertStringContainsString('dashboard/export/provider-parent-appointments.pdf', $httpClient);
        $this->assertStringContainsString('provider_parent_appointments_pdf', $routes);
        $this->assertStringNotContainsString('provider_ids', $teacherScript);
    }

    public function testTeacherDashboardProvidesProviderOnlyPreparationPdfDownloadButton(): void
    {
        $view = file_get_contents(VIEWPATH . 'pages/dashboard_teacher.php');
        $teacherScript = file_get_contents(FCPATH . 'assets/js/pages/dashboard_teacher.js');
        $httpClient = file_get_contents(FCPATH . 'assets/js/http/dashboard_http_client.js');
        $routes = file_get_contents(APPPATH . 'config/routes.php');

        $this->assertStringContainsString('id="dashboard-teacher-preparation-export"', $view);
        $this->assertStringContainsString("lang('dashboard_teacher_download_preparation_pdf')", $view);
        $this->assertStringContainsString("\$preparationPdfLabel = 'Preparation PDF';", $view);
        $this->assertStringContainsString('html_escape($preparationPdfLabel)', $view);
        $this->assertStringContainsString('onPreparationExportClick', $teacherScript);
        $this->assertStringContainsString('downloadProviderPreparationExport(filters)', $teacherScript);
        $this->assertStringContainsString('dashboard/export/provider-preparation.pdf', $httpClient);
        $this->assertStringContainsString('downloadProviderPreparationExport,', $httpClient);
        $this->assertStringContainsString(
            "'dashboard/export/provider-preparation.pdf']['get'] = 'dashboard_export/provider_preparation_pdf'",
            $routes,
        );
        $this->assertStringNotContainsString('provider_ids', $teacherScript);
    }
}
