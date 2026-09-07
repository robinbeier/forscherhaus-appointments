/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.4.0
 * ---------------------------------------------------------------------------- */

const {pipeline} = require('node:stream/promises');
const babel = require('gulp-babel');
const cached = require('gulp-cached');
const css = require('gulp-clean-css');
const fs = require('fs-extra');
const gulp = require('gulp');
const rename = require('gulp-rename');
const sass = require('gulp-sass')(require('sass'));

let deleteSyncPromise;

function getDeleteSync() {
    // `del` >= 8 is ESM-only, so keep the existing CommonJS gulpfile and load it lazily.
    deleteSyncPromise ??= import('del').then((mod) => mod.deleteSync ?? mod.sync ?? mod.default?.sync);
    return deleteSyncPromise;
}

function clean(done) {
    fs.removeSync('assets/js/**/*.min.js');
    fs.removeSync('assets/css/**/*.min.css');
    done();
}

// Consume destination output so promise-based pipelines cannot stall on Vinyl files.
function scripts() {
    return pipeline(
        gulp.src(['assets/js/**/*.js', '!assets/js/**/*.min.js']),
        babel({comments: false}),
        rename({suffix: '.min'}),
        gulp.dest('assets/js').resume(),
    );
}

function styles() {
    return pipeline(
        gulp.src(['assets/css/**/*.scss', '!assets/css/**/*.min.css']),
        cached(),
        sass(),
        gulp.dest('assets/css'),
        css(),
        rename({suffix: '.min'}),
        gulp.dest('assets/css').resume(),
    );
}

function watch(done) {
    gulp.watch(['assets/js/**/*.js', '!assets/js/**/*.min.js'], gulp.parallel(scripts));
    gulp.watch(['assets/css/**/*.scss', '!assets/css/**/*.css'], gulp.parallel(styles));
    done();
}

function vendor(done) {
    getDeleteSync()
        .then((deleteSync) => {
            deleteSync(['assets/vendor/**', '!assets/vendor/index.html']);

            // bootstrap
            gulp.src([
                'node_modules/bootstrap/dist/js/bootstrap.min.js',
                'node_modules/bootstrap/dist/css/bootstrap.min.css',
            ]).pipe(gulp.dest('assets/vendor/bootstrap'));

            // @fortawesome-fontawesome-free
            gulp.src([
                'node_modules/@fortawesome/fontawesome-free/js/fontawesome.min.js',
                'node_modules/@fortawesome/fontawesome-free/js/solid.min.js',
            ]).pipe(gulp.dest('assets/vendor/@fortawesome-fontawesome-free'));

            // cookieconsent
            gulp.src([
                'node_modules/cookieconsent/build/cookieconsent.min.js',
                'node_modules/cookieconsent/build/cookieconsent.min.css',
            ]).pipe(gulp.dest('assets/vendor/cookieconsent'));

            // fullcalendar
            gulp.src(['node_modules/fullcalendar/index.global.min.js']).pipe(gulp.dest('assets/vendor/fullcalendar'));

            // fullcalendar-moment
            gulp.src(['node_modules/@fullcalendar/moment/index.global.min.js']).pipe(
                gulp.dest('assets/vendor/fullcalendar-moment'),
            );

            // jquery
            gulp.src(['node_modules/jquery/dist/jquery.min.js']).pipe(gulp.dest('assets/vendor/jquery'));

            // jquery-jeditable
            gulp.src(['node_modules/jquery-jeditable/dist/jquery.jeditable.min.js']).pipe(
                gulp.dest('assets/vendor/jquery-jeditable'),
            );

            // html2canvas
            gulp.src(['node_modules/html2canvas/dist/html2canvas.min.js']).pipe(gulp.dest('assets/vendor/html2canvas'));

            // jspdf
            gulp.src(['node_modules/jspdf/dist/jspdf.umd.min.js']).pipe(gulp.dest('assets/vendor/jspdf'));

            // qrcode (pre-bundled browser build)
            gulp.src(['resources/vendor/qrcode/qrcode.min.js']).pipe(gulp.dest('assets/vendor/qrcode'));

            // moment
            gulp.src(['node_modules/moment/min/moment.min.js']).pipe(gulp.dest('assets/vendor/moment'));

            // moment-timezone
            gulp.src(['node_modules/moment-timezone/builds/moment-timezone-with-data.min.js']).pipe(
                gulp.dest('assets/vendor/moment-timezone'),
            );

            // @popperjs-core
            gulp.src(['node_modules/@popperjs/core/dist/umd/popper.min.js']).pipe(
                gulp.dest('assets/vendor/@popperjs-core'),
            );

            // select2
            gulp.src([
                'node_modules/select2/dist/js/select2.min.js',
                'node_modules/select2/dist/css/select2.min.css',
            ]).pipe(gulp.dest('assets/vendor/select2'));

            // tippy.js
            gulp.src(['node_modules/tippy.js/dist/tippy-bundle.umd.min.js']).pipe(gulp.dest('assets/vendor/tippy.js'));

            // trumbowyg
            gulp.src([
                'node_modules/trumbowyg/dist/trumbowyg.min.js',
                'node_modules/trumbowyg/dist/ui/trumbowyg.min.css',
            ]).pipe(gulp.dest('assets/vendor/trumbowyg'));

            gulp.src(['node_modules/trumbowyg/dist/ui/icons.svg']).pipe(gulp.dest('assets/vendor/trumbowyg/ui'));

            // flatpickr
            gulp.src([
                'node_modules/flatpickr/dist/flatpickr.min.js',
                'node_modules/flatpickr/dist/flatpickr.min.css',
            ]).pipe(gulp.dest('assets/vendor/flatpickr'));

            gulp.src(['node_modules/flatpickr/dist/themes/material_green.css'])
                .pipe(css())
                .pipe(rename({suffix: '.min'}))
                .pipe(gulp.dest('assets/vendor/flatpickr'));

            // chart.js
            gulp.src(['node_modules/chart.js/dist/chart.umd.min.js']).pipe(gulp.dest('assets/vendor/chart.js'));

            // chartjs-chart-matrix
            gulp.src(['node_modules/chartjs-chart-matrix/dist/chartjs-chart-matrix.min.js']).pipe(
                gulp.dest('assets/vendor/chartjs-chart-matrix'),
            );

            done();
        })
        .catch(done);
}

exports.clean = gulp.series(clean);
exports.vendor = gulp.series(vendor);
exports.scripts = gulp.series(scripts);
exports.styles = gulp.series(styles);
exports.compile = gulp.series(clean, vendor, scripts, styles);
exports.dev = gulp.series(clean, vendor, scripts, styles, watch);
exports.build = exports.compile;
exports.default = exports.dev;
