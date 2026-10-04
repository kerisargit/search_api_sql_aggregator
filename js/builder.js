(function (Drupal, drupalSettings) {
  'use strict';

  function customSqlAllowed() {
    return !(drupalSettings
      && drupalSettings.searchApiSqlAggregator
      && drupalSettings.searchApiSqlAggregator.allowCustomSql === false);
  }

  function unsafeCustomSqlAllowed() {
    return !(drupalSettings
      && drupalSettings.searchApiSqlAggregator
      && drupalSettings.searchApiSqlAggregator.allowUnsafeCustomSql === false);
  }

  function activeDriver() {
    return (drupalSettings && drupalSettings.searchApiSqlAggregator && drupalSettings.searchApiSqlAggregator.dbDriver) || 'mysql';
  }

  function isMysqlDriver() { return activeDriver() === 'mysql'; }

  function sqlT(name) { return '{' + (name || '?') + '}'; }

  function buildSqlHierJoin(src) {
    var tbl    = src.table      || 'taxonomy_term__parent';
    var entCol = src.entity_col || 'entity_id';
    var delPart = src.check_deleted ? ' AND h.deleted = 0' : '';
    return 'LEFT JOIN ' + sqlT(tbl) + ' h\n  ON h.' + entCol + ' = s.value' + delPart;
  }

  function buildSqlHierExpr(src) {
    var parentCol = src.parent_col || 'parent_target_id';
    var nullVal   = (src.null_value !== undefined && src.null_value !== '') ? String(src.null_value) : null;
    var parentRef = (nullVal !== null && /^\d+$/.test(nullVal))
      ? 'NULLIF(h.' + parentCol + ', ' + nullVal + ')'
      : 'h.' + parentCol;
    var castType = isMysqlDriver() ? 'UNSIGNED' : 'INTEGER';
    return 'CAST(COALESCE(' + parentRef + ', s.value) AS ' + castType + ')';
  }

  function buildSqlAggFuncExpr(aggFunc, valueExpr) {
    if (aggFunc !== 'GROUP_CONCAT') return aggFunc + '(' + valueExpr + ')';
    switch (activeDriver()) {
      case 'mysql': return 'GROUP_CONCAT(' + valueExpr + " SEPARATOR ',')";
      case 'pgsql': return 'STRING_AGG(CAST(' + valueExpr + " AS TEXT), ',')";
      default:      return 'GROUP_CONCAT(' + valueExpr + ", ',')"; // sqlite
    }
  }

  function sqlGeneratePreview(blocks) {
    if (!blocks || blocks.length === 0) return '-- No operations configured.';
    var sep = '\n\n' + new Array(61).join('-') + '\n\n';
    return blocks.map(function (op, i) {
      var label = '-- #' + (i + 1) + ' [' + (op.type || 'aggregate').toUpperCase().replace(/_/g, ' ') + ']';
      try { return label + '\n\n' + sqlBuildOp(op); }
      catch (e) { return label + '\n-- Error: ' + e.message; }
    }).join(sep);
  }

  function sqlBuildOp(op) {
    switch (op.type) {
      case 'custom_sql':        return op.sql || '-- SQL not specified.';
      case 'custom_sql_unsafe': return op.sql || '-- SQL not specified.';
      case 'aggregate':      return sqlAggregate(op);
      case 'copy':           return sqlCopy(op);
      case 'null_reset':     return sqlNullReset(op);
      case 'fill_from_union':return sqlFillFromUnion(op);
      case 'priority_fill':  return sqlPriorityFill(op);
      default: return '-- Unknown type: ' + op.type;
    }
  }

  function sqlAggregate(op) {
    var target     = op.target_table  || '';
    var sources    = op.source_tables || [];
    var opts       = op.options || {};
    var hier       = !!opts.hierarchy;
    var mainTable  = opts.main_table  || '';
    var mainColumn = opts.main_column || '';

    if (!target)         return '-- target_table is required.';
    if (!sources.length) return '-- source_tables must be non-empty.';

    var hierSrc  = Object.assign({}, HIER_DEFAULTS, opts.hierarchy_source || {});
    var hierJoin = buildSqlHierJoin(hierSrc);
    var hierExpr = buildSqlHierExpr(hierSrc);

    var parts = [];
    parts.push('DELETE FROM ' + sqlT(target) + ';');

    sources.forEach(function (src) {
      if (!src) return;
      if (hier) {
        parts.push(
          'INSERT INTO ' + sqlT(target) + ' (item_id, value)\n' +
          'SELECT DISTINCT\n' +
          '  s.item_id,\n' +
          '  ' + hierExpr + ' AS val\n' +
          'FROM ' + sqlT(src) + ' s\n' +
          hierJoin + '\n' +
          'WHERE ' + hierExpr + ' <> 0\n' +
          '  AND NOT EXISTS (\n' +
          '    SELECT 1 FROM ' + sqlT(target) + ' a\n' +
          '    WHERE a.item_id = s.item_id\n' +
          '      AND a.value = ' + hierExpr + '\n' +
          '  );'
        );
      } else {
        parts.push(
          'INSERT INTO ' + sqlT(target) + ' (item_id, value)\n' +
          'SELECT s.item_id, s.value\n' +
          'FROM ' + sqlT(src) + ' s\n' +
          'WHERE NOT EXISTS (\n' +
          '  SELECT 1 FROM ' + sqlT(target) + ' a\n' +
          '  WHERE a.item_id = s.item_id AND a.value = s.value\n' +
          ');'
        );
      }
    });

    if (mainTable && mainColumn) {
      var union    = sources.map(function (s) { return 'SELECT item_id, value FROM ' + sqlT(s); });
      var unionStr = union.join('\n    UNION ALL ');
      parts.push('UPDATE ' + sqlT(mainTable) + ' SET ' + mainColumn + ' = NULL;');
      var srcSql = hier
        ? '  SELECT s.item_id, MIN(' + hierExpr + ') AS val\n' +
          '  FROM (\n    ' + unionStr + '\n  ) AS s\n' +
          '  ' + hierJoin + '\n' +
          '  WHERE ' + hierExpr + ' <> 0\n' +
          '  GROUP BY s.item_id'
        : '  SELECT item_id, MIN(value) AS val\n' +
          '  FROM (\n    ' + unionStr + '\n  ) AS flatsrc\n' +
          '  GROUP BY item_id';
      if (isMysqlDriver()) {
        parts.push(
          'UPDATE ' + sqlT(mainTable) + ' AS main\n' +
          'JOIN (\n' + srcSql + '\n) AS src ON src.item_id = main.item_id\n' +
          'SET main.' + mainColumn + ' = src.val;'
        );
      } else {
        parts.push(
          'UPDATE ' + sqlT(mainTable) + ' AS main\n' +
          'SET ' + mainColumn + ' = src.val\n' +
          'FROM (\n' + srcSql + '\n) AS src\n' +
          'WHERE src.item_id = main.item_id;'
        );
      }
    } else if (mainTable) {
      parts.push('-- SKIP main UPDATE: main_column is required when main_table is set.');
    }

    return parts.join('\n\n');
  }

  function sqlCopy(op) {
    var sT = op.source_table || '', sC = op.source_column || '';
    var tT = op.target_table || '', tC = op.target_column || '';
    var jt = ((op.join_type || 'INNER') + '').toUpperCase();
    var co = !!op.coalesce;
    if (!sT || !sC || !tT || !tC) return '-- Incomplete: source/target table and column required.';

    if (isMysqlDriver()) {
      var val = co ? 'COALESCE(s.' + sC + ', t.' + tC + ')' : 's.' + sC;
      return (
        'UPDATE ' + sqlT(tT) + ' t\n' +
        jt + ' JOIN ' + sqlT(sT) + ' s ON s.item_id = t.item_id\n' +
        'SET t.' + tC + ' = ' + val + ';'
      );
    }

    if (jt === 'LEFT') {
      var srcExpr = '(SELECT s.' + sC + ' FROM ' + sqlT(sT) + ' s WHERE s.item_id = t.item_id)';
      var leftVal = co ? 'COALESCE(' + srcExpr + ', ' + tC + ')' : srcExpr;
      return 'UPDATE ' + sqlT(tT) + ' AS t SET ' + tC + ' = ' + leftVal + ';';
    }
    var innerVal = co ? 'COALESCE(s.' + sC + ', t.' + tC + ')' : 's.' + sC;
    return (
      'UPDATE ' + sqlT(tT) + ' AS t\n' +
      'SET ' + tC + ' = ' + innerVal + '\n' +
      'FROM ' + sqlT(sT) + ' s\n' +
      'WHERE s.item_id = t.item_id;'
    );
  }

  function sqlNullReset(op) {
    var tT = op.target_table || '', tC = op.target_column || '';
    if (!tT || !tC) return '-- Incomplete: target_table and target_column required.';
    return 'UPDATE ' + sqlT(tT) + '\nSET ' + tC + ' = NULL;';
  }

  function sqlFillFromUnion(op) {
    var tT   = op.target_table || '', tC = op.target_column || '';
    var srcs = op.sources || [];
    var agg  = ((op.aggregate_func || 'MIN') + '').toUpperCase();
    var jt   = ((op.join_type || 'INNER') + '').toUpperCase();
    var dist = !!op.distinct;
    if (!tT || !tC || !srcs.length) return '-- Incomplete: target and sources required.';
    var sel = dist ? 'SELECT DISTINCT' : 'SELECT';
    var union = srcs.map(function (s) {
      return sel + ' item_id, ' + (s.column || '?') + ' AS val FROM ' + sqlT(s.table);
    });
    var unionStr = union.join('\n    UNION ALL ');
    var aggExpr  = buildSqlAggFuncExpr(agg, 'union_src.val');

    if (isMysqlDriver()) {
      return (
        'UPDATE ' + sqlT(tT) + ' t\n' +
        jt + ' JOIN (\n' +
        '  SELECT item_id, ' + aggExpr + ' AS agg_val\n' +
        '  FROM (\n    ' + unionStr + '\n  ) AS union_src\n' +
        '  GROUP BY item_id\n' +
        ') AS agg ON agg.item_id = t.item_id\n' +
        'SET t.' + tC + ' = agg.agg_val;'
      );
    }

    if (jt === 'LEFT') {
      return (
        'UPDATE ' + sqlT(tT) + ' AS t\n' +
        'SET ' + tC + ' = (\n' +
        '  SELECT ' + aggExpr + ' FROM (\n    ' + unionStr + '\n  ) AS union_src\n' +
        '  WHERE union_src.item_id = t.item_id\n' +
        ');'
      );
    }
    return (
      'UPDATE ' + sqlT(tT) + ' AS t\n' +
      'SET ' + tC + ' = agg.agg_val\n' +
      'FROM (\n' +
      '  SELECT item_id, ' + aggExpr + ' AS agg_val\n' +
      '  FROM (\n    ' + unionStr + '\n  ) AS union_src\n' +
      '  GROUP BY item_id\n' +
      ') AS agg\n' +
      'WHERE agg.item_id = t.item_id;'
    );
  }

  function sqlPriorityFill(op) {
    var tT   = op.target_table || '', tC = op.target_column || '';
    var srcs = op.sources || [];
    if (!tT || !tC || !srcs.length) return '-- Incomplete: target and sources required.';

    if (isMysqlDriver()) {
      var joins = [], coalesce = [];
      srcs.forEach(function (s, i) {
        var a = 'pf' + i;
        joins.push('LEFT JOIN ' + sqlT(s.table) + ' ' + a + ' ON ' + a + '.item_id = t.item_id');
        coalesce.push(a + '.' + (s.column || '?'));
      });
      return (
        'UPDATE ' + sqlT(tT) + ' t\n' +
        joins.join('\n') + '\n' +
        'SET t.' + tC + ' = COALESCE(' + coalesce.join(', ') + ');'
      );
    }

    var subqueries = srcs.map(function (s) {
      return '(SELECT ' + (s.column || '?') + ' FROM ' + sqlT(s.table) + ' WHERE item_id = t.item_id)';
    });
    return 'UPDATE ' + sqlT(tT) + ' AS t SET ' + tC + ' = COALESCE(' + subqueries.join(', ') + ');';
  }

  var HIER_DEFAULTS = {
    table:         'taxonomy_term__parent',
    entity_col:    'entity_id',
    parent_col:    'parent_target_id',
    null_value:    '0',
    check_deleted: true,
  };

  var OP_META = {
    custom_sql: {
      label: 'CUSTOM SQL',
      zones: [],
      extras: [],
    },
    custom_sql_unsafe: {
      label: 'UNSAFE CUSTOM SQL',
      zones: [],
      extras: [],
    },
    aggregate: {
      label: 'AGGREGATE',
      zones: [
        { key: 'target',      label: 'Target Table',            itemType: 'table',  maxOne: true,  optional: false },
        { key: 'sources',     label: 'Source Tables',           itemType: 'table',  maxOne: false, optional: false },
        { key: 'main_column', label: 'Main Column (optional)',  itemType: 'column', maxOne: true,  optional: true  },
      ],
      extras: [],
    },
    copy: {
      label: 'COPY',
      zones: [
        { key: 'source', label: 'Source Column', itemType: 'column', maxOne: true, optional: false },
        { key: 'target', label: 'Target Column', itemType: 'column', maxOne: true, optional: false },
      ],
      extras: ['join_type', 'coalesce'],
    },
    null_reset: {
      label: 'NULL RESET',
      zones: [
        { key: 'target', label: 'Target Column', itemType: 'column', maxOne: true, optional: false },
      ],
      extras: [],
    },
    fill_from_union: {
      label: 'FILL UNION',
      zones: [
        { key: 'target',  label: 'Target Column',  itemType: 'column', maxOne: true,  optional: false },
        { key: 'sources', label: 'Source Columns', itemType: 'column', maxOne: false, optional: false },
      ],
      extras: ['join_type', 'agg_func', 'distinct'],
    },
    priority_fill: {
      label: 'PRIORITY FILL',
      zones: [
        { key: 'target',  label: 'Target Column',                   itemType: 'column', maxOne: true,  optional: false },
        { key: 'sources', label: 'Source Columns (priority order)', itemType: 'column', maxOne: false, optional: false },
      ],
      extras: [],
    },
  };

  var OP_TYPES  = Object.keys(OP_META);
  var AGG_FUNCS = ['MIN', 'MAX', 'SUM', 'COUNT', 'AVG', 'GROUP_CONCAT'];
  var JOIN_TYPES = ['INNER', 'LEFT'];

  Drupal.behaviors.sasBuilder = {
    attach: function (context) {
      context.querySelectorAll('.sas-builder-ui').forEach(function (root) {
        if (root.classList.contains('sas-processed')) return;
        root.classList.add('sas-processed');

        var tablesData  = [];
        var serversData = {};
        var indexesData = {};

        try { tablesData  = JSON.parse(root.querySelector('.sas-data-source')?.textContent   || '[]'); } catch (e) { console.error('SAS tables', e); }
        try { serversData = JSON.parse(root.querySelector('.sas-servers-source')?.textContent || '{}'); } catch (e) { console.error('SAS servers', e); }
        try { indexesData = JSON.parse(root.querySelector('.sas-indexes-source')?.textContent || '{}'); } catch (e) { console.error('SAS indexes', e); }

        var tablesMeta = {};
        tablesData.forEach(function (t) { tablesMeta[t.name] = t; });

        var form        = root.closest('form');
        var textarea    = document.querySelector('.sas-json-storage') ||
                          (form && form.querySelector('.sas-json-storage'));
        var listEl      = root.querySelector('#sas-table-list');
        var searchInput = root.querySelector('#sas-search-input');
        var blocksArea  = root.querySelector('#sas-blocks-area');
        var addBtn      = root.querySelector('#sas-add-block-btn');
        var copyBtn     = root.querySelector('#sas-copy-json-btn');
        var clearBtn    = root.querySelector('#sas-clear-all-btn');
        var suggestBtn  = root.querySelector('#sas-suggest-btn');
        var countEl     = root.querySelector('#sas-block-count');
        var filterBar   = root.querySelector('#sas-filter-bar');

        var tplCache = {};
        function tplEl(id) {
          if (!(id in tplCache)) {
            tplCache[id] = root.querySelector('#' + id);
          }
          return tplCache[id];
        }
        function tplClone(id) {
          var t = tplEl(id);
          return t ? t.content.firstElementChild.cloneNode(true) : null;
        }
        function tplFragment(id) {
          var t = tplEl(id);
          return t ? t.content.cloneNode(true) : document.createDocumentFragment();
        }
        function emptyNode(id, text) {
          var el = tplClone(id);
          if (el) el.textContent = text;
          return el;
        }
        function kindBadge(kind) {
          var s = document.createElement('span');
          s.className   = 'sas-kind-badge sas-kind-badge--' + kind;
          s.textContent = kind;
          return s;
        }
        function suggestIndexBadge(label) {
          var s = document.createElement('span');
          s.className   = 'sas-suggest-index-badge';
          s.textContent = label;
          return s;
        }
        function fillSelect(sel, rows) {
          sel.innerHTML = '';
          rows.forEach(function (r) {
            var o = document.createElement('option');
            o.value       = r[0];
            o.textContent = r[1];
            if (r[2]) o.selected = true;
            sel.appendChild(o);
          });
        }

        var activeBlockId   = null;
        var activeZoneEl    = null;
        var activeFilterIdx = '__all__';
        var expandedTables  = {};
        var isLocked        = false;

        var indexIds = Object.keys(indexesData);
        if (filterBar && indexIds.length > 1) {
          var multiServer = Object.keys(serversData).length > 1;

          filterBar.appendChild(makeFilterTab('__all__', Drupal.t('All'), true));

          var byServer = {};
          indexIds.forEach(function (id) {
            var sid = indexesData[id].server_id;
            if (!byServer[sid]) byServer[sid] = [];
            byServer[sid].push(id);
          });

          Object.entries(byServer).forEach(function (entry) {
            var sid = entry[0], ids = entry[1];
            if (multiServer) {
              var sep = document.createElement('span');
              sep.className   = 'sas-filter-sep';
              sep.textContent = serversData[sid] || sid;
              filterBar.appendChild(sep);
            }
            ids.forEach(function (id) {
              filterBar.appendChild(makeFilterTab(id, indexesData[id].label, false));
            });
          });

          filterBar.addEventListener('click', function (e) {
            var tab = e.target.closest('.sas-filter-tab');
            if (!tab) return;
            filterBar.querySelectorAll('.sas-filter-tab').forEach(function (t) { t.classList.remove('is-active'); });
            tab.classList.add('is-active');
            activeFilterIdx = tab.dataset.filter;
            renderList(searchInput ? searchInput.value : '');
          });
        }

        function makeFilterTab(filterId, label, active) {
          var btn = document.createElement('button');
          btn.type         = 'button';
          btn.className    = 'button button--extrasmall sas-filter-tab' + (active ? ' is-active' : '');
          btn.textContent  = label;
          btn.dataset.filter = filterId;
          return btn;
        }

        function renderList(filterText) {
          filterText = (filterText || '').toLowerCase();
          listEl.innerHTML = '';

          if (tablesData.length === 0) {
            listEl.appendChild(emptyNode('sas-tpl-empty', Drupal.t('No fields available.')));
            return;
          }

          var filtered = tablesData.filter(function (t) {
            var nameOk  = !filterText ||
              (t.name  || '').toLowerCase().includes(filterText) ||
              (t.label || '').toLowerCase().includes(filterText) ||
              (t.columns || []).some(function (c) {
                return (c.name  || '').toLowerCase().includes(filterText) ||
                       (c.label || '').toLowerCase().includes(filterText);
              });
            var indexOk = activeFilterIdx === '__all__' || t.index_id === activeFilterIdx;
            return nameOk && indexOk;
          });

          if (filtered.length === 0) {
            listEl.appendChild(emptyNode('sas-tpl-empty', Drupal.t('No matching fields.')));
            return;
          }

          filtered.forEach(function (t) {
            var isExpanded = !!expandedTables[t.name];
            var treeItem   = tplClone('sas-tpl-tree-item');
            treeItem.dataset.tbl = t.name;

            var ttParts2 = [t.name];
            if (t.property_path) ttParts2.push('Path: '   + t.property_path);
            if (t.index_label)   ttParts2.push('Index: '  + t.index_label);
            if (t.server_label)  ttParts2.push('Server: ' + t.server_label);

            var toggle = treeItem.querySelector('.sas-tree-toggle');
            var info   = treeItem.querySelector('.sas-tree-info');
            var colsEl = treeItem.querySelector('.sas-tree-columns');

            toggle.textContent = isExpanded ? '▼' : '▶';
            info.title = ttParts2.join('\n');
            treeItem.querySelector('.sas-fl-name').textContent = t.label || t.name;
            if (t.kind) {
              treeItem.querySelector('.sas-field-label').appendChild(kindBadge(t.kind));
            }
            treeItem.querySelector('.sas-table-name').textContent = t.name.replace('search_api_db_', '…');
            colsEl.hidden = !isExpanded;

            toggle.addEventListener('click', function (e) {
              e.stopPropagation();
              var nowExpanded = colsEl.hidden;
              colsEl.hidden          = !nowExpanded;
              expandedTables[t.name] = nowExpanded;
              this.textContent = nowExpanded ? '▼' : '▶';
            });

            info.addEventListener('click', function () {
              handleTreeClick(t.name, null);
            });

            (t.columns || []).forEach(function (c) {
              var colRow = tplClone('sas-tpl-col-item');
              if (c.forbidden) colRow.classList.add('sas-col-forbidden');
              colRow.dataset.tbl = t.name;
              colRow.dataset.col = c.name;
              var colTtParts = [t.name + '.' + c.name];
              if (t.property_path) colTtParts.push('Path: '   + t.property_path);
              if (t.index_label)   colTtParts.push('Index: '  + t.index_label);
              if (t.server_label)  colTtParts.push('Server: ' + t.server_label);
              colRow.title = colTtParts.join('\n');
              colRow.querySelector('.sas-col-name').textContent = c.label || c.name;
              colRow.querySelector('.sas-col-type').textContent = c.type || '';
              if (c.forbidden) colRow.appendChild(tplClone('sas-tpl-col-lock'));
              colRow.addEventListener('click', function (e) {
                e.stopPropagation();
                handleTreeClick(t.name, c.name);
              });
              colsEl.appendChild(colRow);
            });

            if (t.property_path) {
              var pathEl = tplClone('sas-tpl-tree-path');
              pathEl.querySelector('.sas-tree-path-value').textContent = t.property_path;
              colsEl.appendChild(pathEl);
            }

            listEl.appendChild(treeItem);
          });
        }

        function handleTreeClick(tableName, colName) {
          if (!activeBlockId || !activeZoneEl) {
            listEl.classList.add('sas-flash');
            setTimeout(function () { listEl.classList.remove('sas-flash'); }, 500);
            return;
          }

          var itemType = activeZoneEl.dataset.itemType;

          if (itemType === 'table') {
            var ul       = activeZoneEl.querySelector('ul');
            var maxOne   = activeZoneEl.dataset.maxOne === '1';
            var existing = ul.querySelectorAll('li');
            var dup = false;
            existing.forEach(function (li) { if (li.dataset.tbl === tableName) dup = true; });
            if (dup) return;
            if (maxOne) { ul.innerHTML = ''; advanceToNextZone(activeZoneEl); }
            appendZoneItem(ul, tableName, null);
            syncToJson();
          }
          else if (itemType === 'column') {
            if (!colName) {
              activeZoneEl.classList.add('sas-zone-flash');
              setTimeout(function () { activeZoneEl.classList.remove('sas-zone-flash'); }, 500);
              return;
            }
            var ul2     = activeZoneEl.querySelector('ul');
            var maxOne2 = activeZoneEl.dataset.maxOne === '1';
            var dup2    = false;
            ul2.querySelectorAll('li').forEach(function (li) {
              if (li.dataset.tbl === tableName && li.dataset.col === colName) dup2 = true;
            });
            if (dup2) return;
            if (maxOne2) { ul2.innerHTML = ''; advanceToNextZone(activeZoneEl); }
            appendZoneItem(ul2, tableName, colName);
            syncToJson();
          }
        }

        function advanceToNextZone(currentZone) {
          var block = currentZone.closest('.sas-block');
          if (!block) return;
          var zones = Array.from(block.querySelectorAll('.sas-drop-zone'));
          var idx   = zones.indexOf(currentZone);
          var next  = zones[idx + 1];
          if (next) setTimeout(function () { setActiveZone(next); }, 50);
        }

        function appendZoneItem(ul, tableName, colName, isColumnZone) {
          var li = tplClone('sas-tpl-zone-item');
          li.dataset.tbl = tableName;
          var needsCol = !!isColumnZone && (colName === null || colName === undefined || colName === '');
          if (!needsCol && colName != null && colName !== '') li.dataset.col = colName;
          if (needsCol) li.classList.add('sas-item-needs-col');

          var meta    = tablesMeta[tableName] || {};
          var display = tableName.replace('search_api_db_', '…');
          if (!needsCol && colName) display += '.' + colName;

          var ttParts = [tableName];
          if (!needsCol && colName) ttParts.push('Column: ' + colName);
          if (meta.property_path)   ttParts.push('Path: '   + meta.property_path);
          if (meta.index_label)     ttParts.push('Index: '  + meta.index_label);
          if (meta.server_label)    ttParts.push('Server: ' + meta.server_label);
          if (needsCol)             ttParts.push(Drupal.t('⚠ Column not selected — click a column in the left tree'));

          var nameEl = li.querySelector('.sas-item-name');
          nameEl.title       = ttParts.join('\n');
          nameEl.textContent = display;
          if (meta.index_label) {
            var badge = document.createElement('small');
            badge.className   = 'sas-item-index-badge';
            badge.title       = meta.index_label;
            badge.textContent = meta.index_label;
            nameEl.appendChild(document.createTextNode(' '));
            nameEl.appendChild(badge);
          }

          li.querySelector('.sas-item-remove').addEventListener('click', function (e) {
            e.stopPropagation();
            li.remove();
            syncToJson();
          });

          var zoneEl  = ul.closest('.sas-drop-zone');
          var blockEl = ul.closest('.sas-block');
          var dropLabel = li.querySelector('.sas-item-drop-index-label');
          if (dropLabel) {
            var showDropToggle = !!(zoneEl && blockEl
              && zoneEl.dataset.zoneKey === 'sources'
              && blockEl.dataset.opType === 'aggregate');
            dropLabel.hidden = !showDropToggle;
            if (showDropToggle) {
              var dropToggle = dropLabel.querySelector('.sas-item-drop-index-toggle');
              dropToggle.checked = true;
              dropToggle.addEventListener('change', function () { syncToJson(); });
            }
          }

          ul.appendChild(li);
        }

        function initBlocks() {
          if (!textarea) return;
          try {
            var raw  = textarea.value.replace(/ /g, ' ');
            var data = raw ? JSON.parse(raw) : [];
            blocksArea.innerHTML = '';
            if (Array.isArray(data) && data.length > 0) {
              data.forEach(function (op) {
                if (!op.options) op.options = {};
                createBlockUI(op);
              });
            }
            else {
              createBlockUI(null);
            }
            syncToJson();
          }
          catch (e) {
            console.error("SAS initBlocks", e);
            blocksArea.innerHTML = '';
            createBlockUI(null);
          }
        }

        function createBlockUI(data) {
          var opType = (data && data.type && OP_META[data.type]) ? data.type : 'aggregate';
          var id     = 'blk_' + Math.random().toString(36).substring(2, 8);

          var block = tplClone('sas-tpl-block');
          block.id             = id;
          block.dataset.opType = opType;

          var allowCustom       = customSqlAllowed();
          var allowUnsafeCustom = unsafeCustomSqlAllowed();
          var typeList = OP_TYPES.filter(function (t) {
            if (t === 'custom_sql')        return allowCustom || opType === 'custom_sql';
            if (t === 'custom_sql_unsafe') return allowUnsafeCustom || opType === 'custom_sql_unsafe';
            return true;
          });
          fillSelect(block.querySelector('.sas-op-type-select'),
            typeList.map(function (t) { return [t, OP_META[t].label, t === opType]; }));

          var badgeEl = block.querySelector('.sas-op-badge');
          badgeEl.className   = 'sas-op-badge sas-op-badge--' + opType.replace(/_/g, '-');
          badgeEl.textContent = OP_META[opType].label;

          var joinVal = (data && data.join_type) ? data.join_type.toUpperCase() : 'INNER';
          fillSelect(block.querySelector('.sas-join-type-select'),
            JOIN_TYPES.map(function (jt) { return [jt, jt + ' JOIN', joinVal === jt]; }));
          var aggVal = (data && data.aggregate_func) ? data.aggregate_func.toUpperCase() : '';
          fillSelect(block.querySelector('.sas-agg-func-select'),
            AGG_FUNCS.map(function (f) { return [f, f, aggVal === f]; }));

          var isHier = !!(data && data.options && data.options.hierarchy);
          block.querySelector('.sas-hier-toggle').checked     = isHier;
          block.querySelector('.sas-coalesce-toggle').checked = !!(data && data.coalesce);
          block.querySelector('.sas-distinct-toggle').checked = !!(data && data.distinct);

          block.querySelector('.sas-hier-label').hidden       = (opType !== 'aggregate');
          block.querySelector('.sas-join-type-select').hidden = (['copy', 'fill_from_union'].indexOf(opType) === -1);
          block.querySelector('.sas-coalesce-label').hidden   = (opType !== 'copy');
          block.querySelector('.sas-distinct-label').hidden   = (opType !== 'fill_from_union');
          block.querySelector('.sas-agg-func-select').hidden  = (opType !== 'fill_from_union');

          var hierSrc = (data && data.options && data.options.hierarchy_source) || {};
          var hierCfg = block.querySelector('.sas-hier-config');
          hierCfg.hidden = (opType !== 'aggregate') || !isHier;
          hierCfg.querySelector('.sas-hier-table').value      = hierSrc.table      || HIER_DEFAULTS.table;
          hierCfg.querySelector('.sas-hier-entity-col').value = hierSrc.entity_col || HIER_DEFAULTS.entity_col;
          hierCfg.querySelector('.sas-hier-parent-col').value = hierSrc.parent_col || HIER_DEFAULTS.parent_col;
          hierCfg.querySelector('.sas-hier-null-value').value =
            (hierSrc.null_value !== undefined ? String(hierSrc.null_value) : HIER_DEFAULTS.null_value);
          hierCfg.querySelector('.sas-hier-check-deleted').checked =
            (hierSrc.check_deleted !== undefined ? hierSrc.check_deleted : HIER_DEFAULTS.check_deleted);

          blocksArea.appendChild(block);
          buildBlockZones(block, opType);
          if (data) populateBlockData(block, data);

          block.querySelector('.sas-op-type-select').addEventListener('change', function () {
            var newType    = this.value;
            var hierToggle = block.querySelector('.sas-hier-toggle');
            block.dataset.opType = newType;
            block.querySelector('.sas-op-badge').className =
              'sas-op-badge sas-op-badge--' + newType.replace(/_/g, '-');
            block.querySelector('.sas-op-badge').textContent = OP_META[newType].label;

            hierToggle.closest('label').hidden                                 = (newType !== 'aggregate');
            block.querySelector('.sas-hier-config').hidden                     = (newType !== 'aggregate') || !hierToggle.checked;
            block.querySelector('.sas-join-type-select').hidden                = (['copy', 'fill_from_union'].indexOf(newType) === -1);
            block.querySelector('.sas-coalesce-label').hidden                  = (newType !== 'copy');
            block.querySelector('.sas-distinct-label').hidden                  = (newType !== 'fill_from_union');
            block.querySelector('.sas-agg-func-select').hidden                 = (newType !== 'fill_from_union');

            buildBlockZones(block, newType);
            syncToJson();
          });

          block.querySelector('.sas-hier-toggle').addEventListener('change', function () {
            block.querySelector('.sas-hier-config').hidden = !this.checked;
            syncToJson();
          });
          block.querySelector('.sas-agg-func-select').addEventListener('change', function () { syncToJson(); });
          block.querySelector('.sas-join-type-select').addEventListener('change',function () { syncToJson(); });
          block.querySelector('.sas-coalesce-toggle').addEventListener('change', function () { syncToJson(); });
          block.querySelector('.sas-distinct-toggle').addEventListener('change', function () { syncToJson(); });

          block.querySelector('.sas-hier-config').querySelectorAll('input').forEach(function (inp) {
            inp.addEventListener('change', function () { syncToJson(); });
            inp.addEventListener('input',  function () { syncToJson(); });
          });
          block.querySelector('.sas-hier-reset').addEventListener('click', function () {
            var cfg = block.querySelector('.sas-hier-config');
            cfg.querySelector('.sas-hier-table').value           = HIER_DEFAULTS.table;
            cfg.querySelector('.sas-hier-entity-col').value      = HIER_DEFAULTS.entity_col;
            cfg.querySelector('.sas-hier-parent-col').value      = HIER_DEFAULTS.parent_col;
            cfg.querySelector('.sas-hier-null-value').value      = HIER_DEFAULTS.null_value;
            cfg.querySelector('.sas-hier-check-deleted').checked = HIER_DEFAULTS.check_deleted;
            syncToJson();
          });

          block.querySelector('.sas-del-btn').addEventListener('click', function (e) {
            e.stopPropagation();
            if (confirm(Drupal.t('Delete this block?'))) { block.remove(); syncToJson(); }
          });

          block.querySelector('.sas-dup-btn').addEventListener('click', function (e) {
            e.stopPropagation();
            if (isLocked) return;
            var dup = createBlockUI(serializeBlock(block).op);
            blocksArea.insertBefore(dup, block.nextSibling);
            syncToJson();
          });

          block.addEventListener('click', function () { setActiveBlock(id); });

          block.setAttribute('draggable', 'true');
          block.addEventListener('dragstart', function (e) {
            if (isLocked) { e.preventDefault(); return; }
            e.dataTransfer.setData('text/plain', block.id);
            e.dataTransfer.effectAllowed = 'move';
            block.classList.add('is-dragging');
          });
          block.addEventListener('dragend', function () {
            block.classList.remove('is-dragging');
            blocksArea.querySelectorAll('.sas-block').forEach(function (b) {
              b.classList.remove('sas-drop-before', 'sas-drop-after');
            });
          });

          setActiveBlock(id);
          var firstZone = block.querySelector('.sas-drop-zone');
          if (firstZone) setActiveZone(firstZone);

          return block;
        }

        function buildBlockZones(block, opType) {
          var content = block.querySelector('.sas-block-content');
          content.textContent = '';

          if (opType === 'custom_sql') {
            var frag = tplFragment('sas-tpl-custom-sql');
            var ta   = frag.querySelector('.sas-custom-sql-input');
            if (ta) ta.addEventListener('input', function () { syncToJson(); });
            content.appendChild(frag);
            return;
          }

          if (opType === 'custom_sql_unsafe') {
            var fragU = tplFragment('sas-tpl-custom-sql-unsafe');
            var taU   = fragU.querySelector('.sas-custom-sql-unsafe-input');
            var cbU   = fragU.querySelector('.sas-custom-sql-unsafe-confirm');
            if (taU) taU.addEventListener('input', function () { syncToJson(); });
            if (cbU) cbU.addEventListener('change', function () { syncToJson(); });
            content.appendChild(fragU);
            return;
          }

          var defs = (OP_META[opType] || OP_META.aggregate).zones;
          defs.forEach(function (def) {
            var zone = tplClone('sas-tpl-zone');
            if (def.optional) zone.classList.add('sas-zone-optional');
            zone.dataset.zoneKey  = def.key;
            zone.dataset.itemType = def.itemType;
            zone.dataset.maxOne   = def.maxOne ? '1' : '0';
            zone.querySelector('h4').textContent = Drupal.t(def.label);
            zone.addEventListener('click', function (e) {
              e.stopPropagation();
              setActiveBlock(block.id);
              setActiveZone(zone);
            });
            content.appendChild(zone);
          });
        }

        function populateBlockData(block, data) {
          var type = data.type || 'aggregate';

          function fillZone(zoneKey, tableName, colName) {
            var zone = block.querySelector('[data-zone-key="' + zoneKey + '"]');
            if (!zone || !tableName) return;
            var isColZone = zone.dataset.itemType === 'column';
            appendZoneItem(zone.querySelector('ul'), tableName, colName !== undefined ? colName : null, isColZone);
          }

          switch (type) {
            case 'custom_sql':
              var sqlInp = block.querySelector('.sas-custom-sql-input');
              if (sqlInp && data.sql) sqlInp.value = data.sql;
              break;

            case 'custom_sql_unsafe':
              var sqlInpU = block.querySelector('.sas-custom-sql-unsafe-input');
              if (sqlInpU && data.sql) sqlInpU.value = data.sql;
              var cbInpU = block.querySelector('.sas-custom-sql-unsafe-confirm');
              if (cbInpU) cbInpU.checked = !!data.confirmed;
              break;

            case 'aggregate':
              fillZone('target', data.target_table, null);
              (data.source_tables || []).forEach(function (t) { fillZone('sources', t, null); });
              var dropOption = data.options && data.options.drop_index_for_sources;
              var neverConfigured = !Array.isArray(dropOption);
              var dropSet = {};
              (neverConfigured ? [] : dropOption).forEach(function (t) { dropSet[t] = true; });
              var sourcesZone = block.querySelector('[data-zone-key="sources"]');
              if (sourcesZone) {
                sourcesZone.querySelectorAll('li').forEach(function (li) {
                  var cb = li.querySelector('.sas-item-drop-index-toggle');
                  if (cb) cb.checked = neverConfigured ? true : !!dropSet[li.dataset.tbl];
                });
              }
              if (data.options && data.options.main_table) {
                fillZone('main_column', data.options.main_table, data.options.main_column || null);
              }
              if (data.options && data.options.hierarchy_source) {
                var hs  = data.options.hierarchy_source;
                var cfg = block.querySelector('.sas-hier-config');
                if (cfg) {
                  if (hs.table         !== undefined) cfg.querySelector('.sas-hier-table').value           = hs.table;
                  if (hs.entity_col    !== undefined) cfg.querySelector('.sas-hier-entity-col').value      = hs.entity_col;
                  if (hs.parent_col    !== undefined) cfg.querySelector('.sas-hier-parent-col').value      = hs.parent_col;
                  if (hs.null_value    !== undefined) cfg.querySelector('.sas-hier-null-value').value      = String(hs.null_value);
                  if (hs.check_deleted !== undefined) cfg.querySelector('.sas-hier-check-deleted').checked = hs.check_deleted;
                }
              }
              break;

            case 'copy':
              fillZone('source', data.source_table, data.source_column);
              fillZone('target', data.target_table, data.target_column);
              var jt1 = block.querySelector('.sas-join-type-select');
              if (jt1 && data.join_type) jt1.value = data.join_type.toUpperCase();
              var co1 = block.querySelector('.sas-coalesce-toggle');
              if (co1 && data.coalesce) co1.checked = true;
              break;

            case 'null_reset':
              fillZone('target', data.target_table, data.target_column);
              break;

            case 'fill_from_union':
              fillZone('target', data.target_table, data.target_column);
              (data.sources || []).forEach(function (s) {
                if (s.table && s.column) fillZone('sources', s.table, s.column);
              });
              var ag1 = block.querySelector('.sas-agg-func-select');
              if (ag1 && data.aggregate_func) ag1.value = data.aggregate_func.toUpperCase();
              var jt2 = block.querySelector('.sas-join-type-select');
              if (jt2 && data.join_type) jt2.value = data.join_type.toUpperCase();
              var di1 = block.querySelector('.sas-distinct-toggle');
              if (di1 && data.distinct) di1.checked = true;
              break;

            case 'priority_fill':
              fillZone('target', data.target_table, data.target_column);
              (data.sources || []).forEach(function (s) {
                if (s.table && s.column) fillZone('sources', s.table, s.column);
              });
              break;
          }
        }

        function setActiveBlock(id) {
          if (isLocked) return;
          root.querySelectorAll('.sas-block').forEach(function (el) { el.classList.remove('is-active'); });
          var el = root.querySelector('#' + id);
          if (el) { el.classList.add('is-active'); activeBlockId = id; }
        }

        function setActiveZone(z) {
          if (isLocked || !z) return;
          root.querySelectorAll('.sas-drop-zone').forEach(function (el) { el.classList.remove('is-active-zone'); });
          z.classList.add('is-active-zone');
          activeZoneEl = z;
        }

        function serializeBlock(block) {
          var type    = block.dataset.opType || 'aggregate';
          var op      = { type: type };
          var isEmpty = false;

          function getZoneItems(zoneKey) {
            var zone = block.querySelector('[data-zone-key="' + zoneKey + '"]');
            if (!zone) return [];
            return Array.from(zone.querySelectorAll('li'));
          }
          function singleTable(zoneKey) {
            var items = getZoneItems(zoneKey);
            return items.length > 0 ? (items[0].dataset.tbl || '') : '';
          }
          function singleTableCol(zoneKey) {
            var items = getZoneItems(zoneKey);
            return items.length > 0
              ? { table: items[0].dataset.tbl || '', column: items[0].dataset.col || '' }
              : null;
          }

          var joinEl  = block.querySelector('.sas-join-type-select');
          var coaEl   = block.querySelector('.sas-coalesce-toggle');
          var dstEl   = block.querySelector('.sas-distinct-toggle');

          switch (type) {
            case 'custom_sql':
              var sqlEl = block.querySelector('.sas-custom-sql-input');
              op.sql = sqlEl ? sqlEl.value.trim() : '';
              if (!op.sql) isEmpty = true;
              break;

            case 'custom_sql_unsafe':
              var sqlElU = block.querySelector('.sas-custom-sql-unsafe-input');
              var cbElU  = block.querySelector('.sas-custom-sql-unsafe-confirm');
              op.sql       = sqlElU ? sqlElU.value.trim() : '';
              op.confirmed = !!(cbElU && cbElU.checked);
              if (!op.sql) isEmpty = true;
              break;

            case 'aggregate':
              op.target_table  = singleTable('target');
              op.source_tables = getZoneItems('sources').map(function (li) { return li.dataset.tbl || ''; }).filter(Boolean);
              var dropIndexFor = getZoneItems('sources')
                .filter(function (li) {
                  var cb = li.querySelector('.sas-item-drop-index-toggle');
                  return !!(cb && cb.checked && li.dataset.tbl);
                })
                .map(function (li) { return li.dataset.tbl; });
              var hierEl      = block.querySelector('.sas-hier-toggle');
              var hierChecked = hierEl ? hierEl.checked : false;
              op.options = { hierarchy: hierChecked };
              if (dropIndexFor.length) {
                op.options.drop_index_for_sources = dropIndexFor;
              }
              var mainColItem = singleTableCol('main_column');
              if (mainColItem && mainColItem.table && mainColItem.column) {
                op.options.main_table  = mainColItem.table;
                op.options.main_column = mainColItem.column;
              }
              if (hierChecked) {
                var hierCfg = block.querySelector('.sas-hier-config');
                if (hierCfg) {
                  var hSrc = {
                    table:         hierCfg.querySelector('.sas-hier-table').value,
                    entity_col:    hierCfg.querySelector('.sas-hier-entity-col').value,
                    parent_col:    hierCfg.querySelector('.sas-hier-parent-col').value,
                    null_value:    hierCfg.querySelector('.sas-hier-null-value').value,
                    check_deleted: hierCfg.querySelector('.sas-hier-check-deleted').checked,
                  };
                  var isDefault = hSrc.table         === HIER_DEFAULTS.table         &&
                                  hSrc.entity_col    === HIER_DEFAULTS.entity_col    &&
                                  hSrc.parent_col    === HIER_DEFAULTS.parent_col    &&
                                  hSrc.null_value    === HIER_DEFAULTS.null_value    &&
                                  hSrc.check_deleted === HIER_DEFAULTS.check_deleted;
                  if (!isDefault) op.options.hierarchy_source = hSrc;
                }
              }
              if (!op.target_table) isEmpty = true;
              break;

            case 'copy':
              var src = singleTableCol('source');
              var tgt = singleTableCol('target');
              op.source_table  = src ? src.table  : '';
              op.source_column = src ? src.column : '';
              op.target_table  = tgt ? tgt.table  : '';
              op.target_column = tgt ? tgt.column : '';
              if (joinEl && joinEl.value !== 'INNER') op.join_type = joinEl.value;
              if (coaEl && coaEl.checked) op.coalesce = true;
              if (!op.source_table || !op.target_table) isEmpty = true;
              break;

            case 'null_reset':
              var t2 = singleTableCol('target');
              op.target_table  = t2 ? t2.table  : '';
              op.target_column = t2 ? t2.column : '';
              if (!op.target_table) isEmpty = true;
              break;

            case 'fill_from_union':
              var t3 = singleTableCol('target');
              op.target_table  = t3 ? t3.table  : '';
              op.target_column = t3 ? t3.column : '';
              op.sources = getZoneItems('sources').map(function (li) {
                return { table: li.dataset.tbl || '', column: li.dataset.col || '' };
              }).filter(function (s) { return s.table; });
              var aggEl = block.querySelector('.sas-agg-func-select');
              op.aggregate_func = aggEl ? aggEl.value : 'MIN';
              if (joinEl && joinEl.value !== 'INNER') op.join_type = joinEl.value;
              if (dstEl && dstEl.checked) op.distinct = true;
              if (!op.target_table) isEmpty = true;
              break;

            case 'priority_fill':
              var t4 = singleTableCol('target');
              op.target_table  = t4 ? t4.table  : '';
              op.target_column = t4 ? t4.column : '';
              op.sources = getZoneItems('sources').map(function (li) {
                return { table: li.dataset.tbl || '', column: li.dataset.col || '' };
              }).filter(function (s) { return s.table; });
              if (!op.target_table) isEmpty = true;
              break;
          }

          return { op: op, isEmpty: isEmpty };
        }

        function syncToJson() {
          if (!textarea) return;

          var result = [];
          blocksArea.querySelectorAll('.sas-block').forEach(function (block) {
            var s = serializeBlock(block);
            if (!s.isEmpty) result.push(s.op);
            // Untouched new blocks stay unmarked; half-filled ones get a hint.
            var started = s.isEmpty && !!block.querySelector('.sas-drop-zone li');
            block.classList.toggle('is-incomplete', started);
            block.title = started ? Drupal.t('Incomplete block: it is not included in the configuration until the required zones are filled.') : '';
          });

          var jsonStr = JSON.stringify(result, null, 2);
          textarea.value = jsonStr;
          var live = (form || document).querySelector('.sas-json-preview');
          if (live) live.value = jsonStr;

          var sqlPre = root.querySelector('.sas-sql-preview');
          if (sqlPre) sqlPre.textContent = sqlGeneratePreview(result);

          if (countEl) {
            countEl.textContent = result.length;
          }
        }

        function fallbackCopy(text, done) {
          var ta = document.createElement('textarea');
          ta.value = text;
          ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0';
          document.body.appendChild(ta);
          ta.focus();
          ta.select();
          try { document.execCommand('copy'); done(); } catch (e) {}
          document.body.removeChild(ta);
        }

        blocksArea.addEventListener('dragover', function (e) {
          if (isLocked) return;
          e.preventDefault();
          var target = e.target.closest('.sas-block');
          if (!target || target.classList.contains('is-dragging')) return;
          blocksArea.querySelectorAll('.sas-block').forEach(function (b) {
            b.classList.remove('sas-drop-before', 'sas-drop-after');
          });
          var rect = target.getBoundingClientRect();
          target.classList.add(e.clientY < rect.top + rect.height / 2 ? 'sas-drop-before' : 'sas-drop-after');
        });

        blocksArea.addEventListener('dragleave', function (e) {
          if (!blocksArea.contains(e.relatedTarget)) {
            blocksArea.querySelectorAll('.sas-block').forEach(function (b) {
              b.classList.remove('sas-drop-before', 'sas-drop-after');
            });
          }
        });

        blocksArea.addEventListener('drop', function (e) {
          if (isLocked) return;
          e.preventDefault();
          var draggedId = e.dataTransfer.getData('text/plain');
          var dragged   = document.getElementById(draggedId);
          if (!dragged || !blocksArea.contains(dragged)) return;

          var before = blocksArea.querySelector('.sas-drop-before');
          var after  = blocksArea.querySelector('.sas-drop-after');

          if (before && before !== dragged) {
            blocksArea.insertBefore(dragged, before);
          }
          else if (after && after !== dragged) {
            blocksArea.insertBefore(dragged, after.nextSibling);
          }

          blocksArea.querySelectorAll('.sas-block').forEach(function (b) {
            b.classList.remove('sas-drop-before', 'sas-drop-after', 'is-dragging');
          });
          syncToJson();
        });

        function setLocked(locked) {
          isLocked = locked;
          root.classList.toggle('is-locked', locked);

          blocksArea.querySelectorAll('input, select').forEach(function (el) {
            el.disabled = locked;
          });
          blocksArea.querySelectorAll('.sas-del-btn, .sas-dup-btn, .sas-item-remove').forEach(function (el) {
            el.disabled = locked;
          });

          if (locked) {
            blocksArea.querySelectorAll('.sas-block').forEach(function (el) { el.classList.remove('is-active'); });
            blocksArea.querySelectorAll('.sas-drop-zone').forEach(function (el) { el.classList.remove('is-active-zone'); });
            activeBlockId = null;
            activeZoneEl  = null;
          }

          if (lockBtn) {
            lockBtn.textContent = locked ? Drupal.t('Edit') : Drupal.t('Lock');
            lockBtn.classList.toggle('is-active', locked);
          }
        }

        var lockBtn = null;
        var headerActions = root.querySelector('.sas-header-actions');
        if (headerActions) {
          lockBtn = document.createElement('button');
          lockBtn.type      = 'button';
          lockBtn.className = 'button button--small';
          lockBtn.textContent = Drupal.t('Lock');
          headerActions.insertBefore(lockBtn, headerActions.firstChild);
          lockBtn.addEventListener('click', function () { setLocked(!isLocked); });
        }

        renderList('');
        initBlocks();

        if (searchInput) {
          searchInput.addEventListener('input', function (e) { renderList(e.target.value); });
        }

        if (addBtn) {
          addBtn.addEventListener('click', function () {
            if (isLocked) return;
            createBlockUI(null);
            blocksArea.scrollTop = blocksArea.scrollHeight;
            syncToJson();
          });
        }

        if (copyBtn) {
          copyBtn.addEventListener('click', function () {
            var json = textarea ? textarea.value : '';
            if (!json) return;
            function markCopied() {
              copyBtn.classList.add('is-copied');
              copyBtn.textContent = Drupal.t('Copied!');
              setTimeout(function () {
                copyBtn.classList.remove('is-copied');
                copyBtn.textContent = Drupal.t('Copy JSON');
              }, 1800);
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
              navigator.clipboard.writeText(json).then(markCopied, function () { fallbackCopy(json, markCopied); });
            }
            else {
              fallbackCopy(json, markCopied);
            }
          });
        }

        if (clearBtn) {
          clearBtn.addEventListener('click', function () {
            if (isLocked) return;
            if (confirm(Drupal.t('Remove all blocks? This cannot be undone.'))) {
              blocksArea.innerHTML = '';
              syncToJson();
            }
          });
        }

        function computeSuggestGroups(mode) {
          var groups = {};
          tablesData.forEach(function (t) {
            if (!t.property_path) return;
            var segs = t.property_path.split(':');
            var last = segs[segs.length - 1];
            if (!last || last === 'entity') return;
            var key = (mode === 'multi') ? last : (last + ':' + (t.index_id || ''));
            if (!groups[key]) {
              groups[key] = {
                last:         last,
                index_id:     t.index_id    || '',
                index_label:  t.index_label || '',
                server_id:    t.server_id   || '',
                server_label: t.server_label|| '',
                fields:       [],
              };
            }
            groups[key].fields.push(t);
          });
          return Object.values(groups).filter(function (g) { return g.fields.length >= 2; });
        }

        function showSuggestPanel(groups, mode, showAll) {
          var old = root.querySelector('.sas-suggest-panel');
          if (old) old.remove();

          mode    = mode    || 'mono';
          showAll = !!showAll;

          var allTablesSorted = tablesData.slice()
            .sort(function (a, b) { return a.name < b.name ? -1 : a.name > b.name ? 1 : 0; });

          var panel = tplClone('sas-tpl-suggest-panel');

          panel.querySelectorAll('.sas-suggest-mode-btn').forEach(function (b) {
            b.classList.toggle('is-active', b.dataset.mode === mode);
          });
          panel.querySelector('.sas-suggest-show-all-chk').checked = showAll;

          function fillTargetSelect(selEl, list, selectedName, withIdx) {
            selEl.innerHTML = '';
            var ph = document.createElement('option');
            ph.value       = '';
            ph.textContent = Drupal.t('— pick a target —');
            selEl.appendChild(ph);
            list.forEach(function (t) {
              var kindTag = (t.kind && t.kind !== 'field') ? ' [' + t.kind + ']' : '';
              var idxTag  = (withIdx && t.index_label) ? ' (' + t.index_label + ')' : '';
              var o = document.createElement('option');
              o.value       = t.name;
              o.textContent = t.name + kindTag + idxTag;
              if (t.name === selectedName) o.selected = true;
              selEl.appendChild(o);
            });
          }

          if (mode === 'mono' && groups.length > 0) {
            var suggestIndexes = {};
            groups.forEach(function (g) {
              if (g.index_id && !suggestIndexes[g.index_id]) {
                suggestIndexes[g.index_id] = g.index_label || g.index_id;
              }
            });
            var suggestIndexIds = Object.keys(suggestIndexes);
            if (suggestIndexIds.length > 1) {
              var bar    = tplClone('sas-tpl-suggest-filter-bar');
              var allTab = tplClone('sas-tpl-suggest-filter-tab');
              allTab.classList.add('is-active');
              allTab.dataset.filter = '__all__';
              allTab.textContent    = Drupal.t('All');
              bar.appendChild(allTab);
              suggestIndexIds.forEach(function (iid) {
                var tab = tplClone('sas-tpl-suggest-filter-tab');
                tab.dataset.filter = iid;
                tab.textContent    = suggestIndexes[iid];
                bar.appendChild(tab);
              });
              panel.appendChild(bar);
            }
          }

          if (groups.length === 0) {
            panel.appendChild(tplClone('sas-tpl-suggest-empty'));
          } else {
            groups.forEach(function (g, gi) {
              var defaultTarget = '';
              g.fields.forEach(function (t) {
                if (!defaultTarget && (t.kind === 'aggregated' || t.name.slice(-11) === '_aggregated' || t.name.slice(-4) === '_agg')) {
                  defaultTarget = t.name;
                }
              });

              var mainTableEntry = null;
              tablesData.forEach(function (t) {
                if (!mainTableEntry && t.kind === 'main' && t.index_id === g.index_id) {
                  mainTableEntry = t;
                }
              });

              var groupEl = tplClone('sas-tpl-suggest-group');
              groupEl.dataset.gi      = gi;
              groupEl.dataset.indexId = g.index_id;
              groupEl.querySelector('.sas-suggest-group-last').textContent = g.last;

              var countEl2 = groupEl.querySelector('.sas-suggest-count');
              if (mode === 'mono' && g.index_label) {
                groupEl.querySelector('.sas-suggest-group-head')
                  .insertBefore(suggestIndexBadge(g.index_label), countEl2);
              }
              countEl2.textContent = Drupal.t('@n tables', { '@n': g.fields.length });

              var sourcesEl = groupEl.querySelector('.sas-suggest-sources');
              g.fields.forEach(function (t) {
                var path    = t.property_path || t.name;
                var tooltip = t.name +
                  (path          ? '\nPath: '   + path          : '') +
                  (t.index_label ? '\nIndex: '  + t.index_label : '') +
                  (t.server_label? '\nServer: ' + t.server_label: '');
                var row = tplClone('sas-tpl-suggest-source-row');
                row.dataset.name = t.name;
                row.title        = tooltip;
                if (t.name === defaultTarget) row.hidden = true;
                var codeEl = row.querySelector('.sas-suggest-table-name');
                if (t.kind && t.kind !== 'field') row.insertBefore(kindBadge(t.kind), codeEl);
                if (mode === 'multi' && t.index_label) row.insertBefore(suggestIndexBadge(t.index_label), row.firstChild);
                codeEl.textContent = t.name;
                row.querySelector('.sas-suggest-path').textContent = path;
                sourcesEl.appendChild(row);
              });

              if (mainTableEntry) {
                var hint = tplClone('sas-tpl-suggest-main-hint');
                hint.querySelector('.sas-suggest-main-hint-name').textContent = mainTableEntry.name;
                groupEl.insertBefore(hint, groupEl.querySelector('.sas-suggest-target-row'));
              }

              fillTargetSelect(
                groupEl.querySelector('.sas-suggest-target-sel'),
                showAll ? allTablesSorted : g.fields,
                defaultTarget,
                showAll
              );
              if (showAll) {
                var exp = groupEl.querySelector('.sas-suggest-expand-target');
                if (exp) exp.remove();
              }

              panel.appendChild(groupEl);
            });
          }

          panel.querySelectorAll('.sas-suggest-target-sel').forEach(function (sel) {
            sel.addEventListener('change', function () {
              var selected = sel.value;
              var groupEl  = sel.closest('.sas-suggest-group');
              groupEl.querySelectorAll('.sas-suggest-source-row').forEach(function (row) {
                row.hidden = !!(selected && row.dataset.name === selected);
              });
            });
          });

          panel.querySelectorAll('.sas-suggest-expand-target').forEach(function (expandBtn) {
            var expanded = false;
            expandBtn.addEventListener('click', function () {
              expanded = !expanded;
              var groupEl = expandBtn.closest('.sas-suggest-group');
              var gi      = parseInt(groupEl.dataset.gi, 10);
              var g       = groups[gi];
              var selEl   = groupEl.querySelector('.sas-suggest-target-sel');
              fillTargetSelect(selEl, expanded ? allTablesSorted : g.fields, selEl.value, expanded);
              expandBtn.textContent = expanded ? Drupal.t('Less') : Drupal.t('All');
              expandBtn.classList.toggle('is-active', expanded);
            });
          });

          panel.querySelectorAll('.sas-suggest-create').forEach(function (btn) {
            btn.addEventListener('click', function () {
              var groupEl      = btn.closest('.sas-suggest-group');
              var gi           = parseInt(groupEl.dataset.gi, 10);
              var g            = groups[gi];
              var selEl        = groupEl.querySelector('.sas-suggest-target-sel');
              var target       = selEl ? selEl.value : '';
              if (!target) {
                // A block without a target never makes it into the JSON.
                if (selEl) {
                  selEl.classList.remove('sas-flash');
                  void selEl.offsetWidth;
                  selEl.classList.add('sas-flash');
                  selEl.focus();
                }
                btn.textContent = Drupal.t('Pick a target first');
                setTimeout(function () { btn.textContent = Drupal.t('Create block'); }, 2000);
                return;
              }
              var sources      = g.fields
                .map(function (t) { return t.name; })
                .filter(function (n) { return n !== target; });
              var mainEntry    = null;
              tablesData.forEach(function (t) {
                if (!mainEntry && t.kind === 'main' && t.index_id === g.index_id) mainEntry = t;
              });
              var opts = { hierarchy: false };
              if (mainEntry && target.indexOf(mainEntry.name + '_') === 0) {
                var derivedCol = target.slice(mainEntry.name.length + 1);
                var mainCols   = (tablesMeta[mainEntry.name] || {}).columns || [];
                var colExists  = mainCols.some(function (c) { return c.name === derivedCol && !c.forbidden; });
                if (colExists) {
                  opts.main_table  = mainEntry.name;
                  opts.main_column = derivedCol;
                }
              }
              createBlockUI({
                type:          'aggregate',
                target_table:  target,
                source_tables: sources,
                options:       opts,
              });
              syncToJson();
              btn.textContent = Drupal.t('✓ Added');
              btn.disabled    = true;
              setTimeout(function () {
                btn.textContent = Drupal.t('Create block');
                btn.disabled    = false;
              }, 2000);
            });
          });

          var closeBtn = panel.querySelector('.sas-suggest-close');
          if (closeBtn) closeBtn.addEventListener('click', function () { panel.remove(); });

          panel.querySelectorAll('.sas-suggest-filter-tab').forEach(function (tab) {
            tab.addEventListener('click', function () {
              panel.querySelectorAll('.sas-suggest-filter-tab').forEach(function (t) {
                t.classList.remove('is-active');
              });
              tab.classList.add('is-active');
              var filter = tab.dataset.filter;
              panel.querySelectorAll('.sas-suggest-group').forEach(function (g) {
                g.hidden = (filter !== '__all__' && g.dataset.indexId !== filter);
              });
            });
          });

          var showAllChk = panel.querySelector('.sas-suggest-show-all-chk');
          if (showAllChk) {
            showAllChk.addEventListener('change', function () {
              showSuggestPanel(groups, mode, this.checked);
            });
          }

          panel.querySelectorAll('.sas-suggest-mode-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
              showSuggestPanel(computeSuggestGroups(btn.dataset.mode), btn.dataset.mode, showAll);
            });
          });

          var sqlDetails = root.querySelector('.sas-sql-details');
          root.insertBefore(panel, sqlDetails || null);
        }

        if (suggestBtn) {
          suggestBtn.addEventListener('click', function () {
            if (isLocked) return;
            var existing = root.querySelector('.sas-suggest-panel');
            if (existing) { existing.remove(); return; }
            showSuggestPanel(computeSuggestGroups('mono'), 'mono');
          });
        }

      }); // forEach root
    }
  };

})(Drupal, drupalSettings);
