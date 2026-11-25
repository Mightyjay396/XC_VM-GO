<script>
    (function () {
        var $ = window.jQuery;
        if (!$) {
            return;
        }
        var L = <?= json_encode(\XcVm\Module\Providerimport\ProviderImportController::jsStrings(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var rAll = [], rRows = [], rBusy = false, rVodRunning = false, rPoll = null, rWasRunning = false;

        function tr(k, r) {
            var s = L[k] || k;
            Object.keys(r || {}).forEach(function (x) { s = s.split('{' + x + '}').join(r[x]); });
            return s;
        }

        function mode() {
            return $('#content_kind').val();
        }

        function applyFilter() {
            var k = mode() === 'live' ? $('#kind_filter').val() : 'all', h = $('#hide_imported').is(':checked');
            rRows = rAll.filter(function (r) { return (k === 'all' || r.kind === k) && !(h && r.imported); });
            var n247 = rAll.filter(function (r) { return r.kind === '247'; }).length;
            $('#kind-counts').text(mode() === 'live' ? tr('counts', {tv: rAll.length - n247, n247: n247}) : '');
            render();
        }

        function esc(s) {
            return $('<div>').text(s == null ? '' : String(s)).html();
        }

        function toast(msg, type) {
            if (window.xcToast) {
                xcToast(msg, type || 'info');
            } else {
                alert(msg);
            }
        }

        var rBase = window.location.pathname.replace(/providerimport\/?$/, '');

        function post(action, fd) {
            return fetch(rBase + 'api?action=' + action, {method: 'POST', body: fd, headers: {'X-Requested-With': 'XMLHttpRequest'}})
                .then(function (r) { return r.text(); })
                .then(function (t) { try { return JSON.parse(t); } catch (e) { return {result: false, error: tr('bad_response')}; } });
        }

        function fd(obj) {
            var f = new FormData();
            Object.keys(obj).forEach(function (k) {
                [].concat(obj[k]).forEach(function (v) { f.append(Array.isArray(obj[k]) ? k + '[]' : k, v); });
            });
            return f;
        }

        function selected() {
            return $('#list-body .row-chk:checked').map(function () { return parseInt(this.value, 10); }).get();
        }

        function refreshCount() {
            $('#sel-count').text(selected().length);
        }

        function lockImport() {
            $('#btn-import').prop('disabled', rBusy || (mode() !== 'live' && rVodRunning));
        }

        function showJob(job) {
            rVodRunning = !!(job && job.running);
            lockImport();
            if (!job || mode() === 'live') {
                $('#vod-status').addClass('d-none');
            } else {
                $('#vod-status').removeClass('d-none alert-info alert-success').addClass(job.running ? 'alert-info' : 'alert-success')
                    .text(tr(job.running ? 'status_running' : 'status_done', {created: job.created, total: job.total}));
            }
            if (rWasRunning && !rVodRunning && !$('#list-card').hasClass('d-none')) {
                toast(tr('import_done'), 'success');
                $('#btn-load').click();
            }
            rWasRunning = rVodRunning;
            clearTimeout(rPoll);
            if (rVodRunning) {
                rPoll = setTimeout(checkStatus, 5000);
            }
        }

        function checkStatus() {
            post('providerimport_pstatus', fd({})).then(function (d) { if (d.result) { showJob(d.job); } });
        }

        function render() {
            $('#list-count').text(rRows.length);
            $('#list-body').html(rRows.map(function (r) {
                var icon = r.icon ? '<img loading="lazy" src="' + rBase + 'resize?maxw=96&maxh=32&url=' + encodeURIComponent(r.icon) + '" alt="">' : '';
                var st = r.imported ? '<span class="badge bg-label-secondary">' + esc(tr('already_imported')) + '</span>' : '<span class="badge bg-label-success">' + esc(tr('new')) + '</span>';
                var kind = mode() === 'movie' && r.episode ? '<span class="badge bg-label-warning">' + esc(tr('badge_episode')) + '</span>'
                    : mode() === 'movie' ? '<span class="badge bg-label-info">' + esc(tr('badge_movie')) + '</span>' + (r.year ? ' <small class="text-muted">' + esc(r.year) + '</small>' : '')
                    : mode() === 'series' ? '<span class="badge bg-label-warning">' + esc(tr('badge_series')) + '</span>' + (r.year ? ' <small class="text-muted">' + esc(r.year) + '</small>' : '')
                    : r.kind === '247' ? '<span class="badge bg-label-warning">24/7</span>' : '<span class="badge bg-label-primary">' + esc(tr('badge_tv')) + '</span>';
                return '<tr><td><input type="checkbox" class="form-check-input row-chk" value="' + r.stream_id + '"></td><td>' + icon + '</td><td>' + esc(r.name) +
                    '</td><td>' + kind + '</td><td>' + (r.category ? esc(r.category) : '<em class="text-muted">' + esc(tr('no_category')) + '</em>') + '</td><td class="small text-muted">' + esc(r.channel_id) + '</td><td>' + st + '</td></tr>';
            }).join(''));
            $('#chk-all').prop('checked', false);
            refreshCount();
            $('#list-card').removeClass('d-none');
        }

        function options(extra) {
            var o = {
                provider_id: $('#provider_id').val(),
                category_mode: $('#category_mode').val(),
                fixed_category: $('#fixed_category').val() || '0',
                fallback_category: $('#fallback_category').val() || '0',
                servers: $('#servers').val() || [],
                on_demand: $('#on_demand').val() || [],
                bouquets: $('#bouquets').val() || []
            };
            return fd($.extend(o, extra));
        }

        function runImport(ids, done, created) {
            var batch = ids.slice(0, 100), rest = ids.slice(100);
            post('providerimport_pimport', options({stream_ids: batch})).then(function (d) {
                created = created.concat((d && d.created_categories) || []);
                if (!d || !d.result) {
                    rBusy = false;
                    lockImport();
                    $('#import-result').html('<div class="alert alert-danger mb-0">' + esc((d && d.error) || tr('unknown_error')) + (done ? '<br>' + esc(tr('partial_done', {n: done})) : '') + '</div>');
                    return;
                }
                done += batch.length;
                var p = Math.round(done * 100 / window.rImportTotal);
                $('#import-progress-bar').css('width', p + '%').text(done + ' / ' + window.rImportTotal + ' (' + p + '%)');
                if (rest.length) {
                    runImport(rest, done, created);
                    return;
                }
                rBusy = false;
                lockImport();
                $('#import-result').html('<div class="alert alert-success mb-0">' + esc(tr('channels_imported', {n: done})) + '<br>' +
                    (created.length ? esc(tr('created_cats', {list: created.join(', ')})) : esc(tr('no_new_cat'))) + '</div>');
                toast(tr('import_done'), 'success');
                $('#btn-load').click();
            });
        }

        $(function () {
            $('select').each(function () { $(this).select2({width: '100%'}); });

            $('#kind_filter').on('change', applyFilter);
            $('#hide_imported').on('change', function () {
                if (!$('#list-card').hasClass('d-none')) {
                    $('#btn-load').click();
                }
            });

            $('#content_kind').on('change', function () {
                var v = mode() !== 'live';
                $('.live-only, #live-options-col').toggleClass('d-none', v);
                $('#vod-options-card').toggleClass('d-none', !v);
                rAll = [];
                $('#list-card').addClass('d-none');
                $('#provider_id').trigger('change');
                checkStatus();
            });

            $('#category_mode').on('change', function () {
                $('#fixed_category_wrap').toggleClass('d-none', $(this).val() !== 'fixed');
            });

            $('#provider_id').on('change', function () {
                var pid = $(this).val();
                $('#provider_category').prop('disabled', true).html('<option value="__all__">' + esc(tr('all_categories')) + '</option>').trigger('change');
                if (pid === '0') {
                    return;
                }
                var vod = mode() !== 'live';
                post(vod ? 'providerimport_pvodcats' : 'providerimport_pcats', fd({provider_id: pid, kind: mode()})).then(function (d) {
                    if (!d.result) {
                        toast(d.error || tr('error'), 'error');
                        return;
                    }
                    d.categories.forEach(function (c) {
                        var label = (c.name || tr('no_cat_label')) + (c.count != null ? ' (' + c.count + ')' : '') + (!vod && c.name && c.existing_id ? ' ✓ ' + tr('exists') : '');
                        $('#provider_category').append(new Option(label, vod ? c.id : c.name));
                    });
                    $('#provider_category').prop('disabled', false).trigger('change');
                });
            });

            $('#btn-load').on('click', function () {
                if ($('#provider_id').val() === '0') {
                    toast(tr('choose_provider'), 'error');
                    return;
                }
                $('#btn-load').prop('disabled', true);
                post(mode() === 'live' ? 'providerimport_plist' : 'providerimport_pvodlist', fd({provider_id: $('#provider_id').val(), kind: mode(), category: $('#provider_category').val(), search: $('#search').val(), hide_imported: $('#hide_imported').is(':checked') ? '1' : ''})).then(function (d) {
                    $('#btn-load').prop('disabled', false);
                    if (!d.result) {
                        toast(d.error || tr('error'), 'error');
                        return;
                    }
                    rAll = d.rows.map(function (r) { r.stream_id = r.stream_id || r.id; r.category = r.category || ''; r.channel_id = r.channel_id || ''; return r; });
                    var w = [];
                    if (d.truncated) { w.push(tr('truncated')); }
                    if (mode() === 'live' && !d.types_known) { w.push(tr('types_unknown')); }
                    if (!d.rows.length) { w.push(d.hidden ? tr('empty_hidden', {n: d.hidden}) : tr('empty_list')); }
                    $('#list-warning').toggleClass('d-none', !w.length).text(w.join(' '));
                    applyFilter();
                    if (d.job !== undefined) { showJob(d.job); }
                });
            });

            $('#search').on('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    $('#btn-load').click();
                }
            });

            $('#chk-all').on('change', function () {
                $('#list-body .row-chk').prop('checked', this.checked);
                refreshCount();
            });
            $('#list-body').on('change', '.row-chk', refreshCount);
            $('#btn-sel-new').on('click', function () {
                $('#list-body .row-chk').each(function (i) { this.checked = !rRows[i].imported; });
                refreshCount();
            });

            $('#btn-import').on('click', function () {
                var ids = selected();
                if (!ids.length || rBusy) {
                    toast(tr('select_one'), 'error');
                    return;
                }
                if (!confirm(tr('confirm_import', {n: ids.length}))) {
                    return;
                }
                if (mode() !== 'live') {
                    var vf = options({ids: ids, kind: mode()});
                    ($('#vod_servers').val() || []).forEach(function (v) { vf.append('vod_servers[]', v); });
                    ($('#vod_bouquets').val() || []).forEach(function (v) { vf.append('vod_bouquets[]', v); });
                    vf.append('movie_fallback', $('#movie_fallback').val() || '0');
                    vf.append('series_fallback', $('#series_fallback').val() || '0');
                    vf.append('create_categories', $('#create_categories').is(':checked') ? '1' : '');
                    vf.append('vod_mode', $('input[name=vod_mode]:checked').val() || 'direct');
                    $('#btn-import').prop('disabled', true);
                    $('#import-result').html('<div class="alert alert-info mb-0">' + esc(tr(mode() === 'series' ? 'preparing_series' : 'preparing')) + '</div>');
                    post('providerimport_pvodqueue', vf).then(function (d) {
                        lockImport();
                        $('#import-result').html(d.result
                            ? '<div class="alert alert-success mb-0">' + esc(tr('vod_queued', {n: d.queued}) + ' ' + tr('vod_split', {movies: d.movies, episodes: d.episodes})) + (d.created_categories.length ? '<br>' + esc(tr('created_cats', {list: d.created_categories.join(', ')})) : '') + '</div>'
                            : '<div class="alert alert-danger mb-0">' + esc(d.error || tr('error')) + '</div>');
                        checkStatus();
                    });
                    return;
                }
                rBusy = true;
                window.rImportTotal = ids.length;
                lockImport();
                $('#import-result').empty();
                $('#import-progress').removeClass('d-none');
                $('#import-progress-bar').css('width', '0%').text('0 / ' + ids.length);
                runImport(ids, 0, []);
            });

            checkStatus();
        });
    })();
</script>
</body>
</html>
