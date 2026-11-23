// =====================================================
//  Matatu Stage System - app.js  (JavaScript part)
//  One file for all pages. Each page's <body> has a
//  data-page name, so only that page's code runs.
//
//  The browser only shows things and sends requests.
//  PHP (the api folder) does the real work: it saves
//  reports in MySQL, runs the 3-minute and outlier
//  checks, and works out the ETA.
// =====================================================


// ---------- 1. SETTINGS AND DATA ----------
var API = "api/";       // folder with the PHP files
var routes = {};        // filled from api/routes.php when the page starts


// ---------- 2. SMALL HELPER FUNCTIONS ----------
function pad(n) {
  if (n < 10) { return "0" + n; }
  return "" + n;
}

function formatTime(ms) {
  var d = new Date(ms);
  return pad(d.getHours()) + ":" + pad(d.getMinutes());
}

function showMessage(box, text, type) {
  if (box == null) { return; }
  box.textContent = text;
  box.className = "message " + type;     // type is "success" or "error"
  box.style.display = "block";
}

function hideMessage(box) {
  if (box != null) { box.style.display = "none"; }
}

function etaText(minutes) {
  if (minutes == null) { return "--"; }
  if (minutes <= 0) { return "Due"; }
  return minutes + " min";
}


// ---------- 3. TALKING TO PHP ----------
// file  = which PHP file, e.g. "report.php"
// data  = null for a normal GET, or an object like {route: "33", stage: 2} to POST
// done  = function that receives the answer from PHP
function callApi(file, data, done) {
  var options = { credentials: "same-origin" };

  if (data != null) {
    options.method = "POST";
    if (data instanceof FormData) {
      options.body = data;                       // used for file uploads
    } else {
      var form = new FormData();
      for (var key in data) {
        form.append(key, data[key]);
      }
      options.body = form;
    }
  }

  fetch(API + file, options)
    .then(function (response) { return response.json(); })
    .then(
      function (result) {
        if (result.loginNeeded) {                // session ended: go to login
          window.location.href = "login.html";
          return;
        }
        done(result);
      },
      function () {
        done({ ok: false, error: "Could not reach the server." });
      }
    );
}

// get the routes and stages from the database
function loadRoutes(done) {
  callApi("routes.php", null, function (result) {
    if (result.ok) {
      routes = result.routes;
    }
    done(result.ok);
  });
}

// protect a page: only let in the roles in the list, otherwise go to login
function requireLogin(allowedRoles, done) {
  callApi("me.php", null, function (me) {
    if (!me.ok || !me.loggedIn || allowedRoles.indexOf(me.role) == -1) {
      window.location.href = "login.html";
      return;
    }
    done(me);
  });
}

// the "stage" box on the commuter page stores the stage number (0 = first)
function stageNameOf(route, index) {
  return routes[route].stages[index];
}


// ---------- 4. COMMUTER PAGE (index.html) ----------
function startCommuterPage() {
  var routeSelect = document.getElementById("route");
  var stageSelect = document.getElementById("stage");
  var form = document.getElementById("eta-form");
  var searchBox = document.getElementById("search");
  var reportBtn = document.getElementById("report-btn");
  var statusBar = document.getElementById("status-bar");

  // fill the route dropdown
  function fillRoutes() {
    routeSelect.innerHTML = "";
    for (var id in routes) {
      var op = document.createElement("option");
      op.value = id;
      op.textContent = routes[id].name;
      routeSelect.appendChild(op);
    }
  }

  // fill the stage dropdown with the stages of the chosen route
  function fillStages() {
    var list = routes[routeSelect.value].stages;
    stageSelect.innerHTML = "";
    for (var i = 0; i < list.length; i++) {
      var op = document.createElement("option");
      op.value = i;
      op.textContent = list[i];
      stageSelect.appendChild(op);
    }
  }

  // ----- showing the answer from api/dashboard.php -----
  function showEta(data) {
    var etaBox = document.getElementById("eta-time");
    var note = document.getElementById("eta-note");

    document.getElementById("eta-route").textContent = "Route " + data.route;
    document.getElementById("eta-sub").textContent = "Your stage: " + data.stageName;

    if (data.firstStage) {
      etaBox.textContent = "--";
      note.textContent = "This is the first stage. Matatus fill up here and leave.";
    } else if (data.eta == null) {
      etaBox.textContent = "--";
      note.textContent = "No recent reports yet. Be the first to report a departure.";
    } else {
      etaBox.textContent = etaText(data.eta.minutes);
      note.textContent = "Based on a matatu that left " + data.eta.from + " " + data.eta.ago + " min ago";

      // keep the last ETA so it can be shown if the network drops
      var saved = { text: etaBox.textContent, stage: data.stageName, time: Date.now() };
      localStorage.setItem("lastEta", JSON.stringify(saved));
    }
  }

  function showStageList(data) {
    var ul = document.getElementById("stage-list");
    ul.innerHTML = "";

    for (var i = 0; i < data.stages.length; i++) {
      var s = data.stages[i];
      var li = document.createElement("li");
      var name = document.createElement("span");
      var badge = document.createElement("span");

      name.textContent = s.name;
      if (i == parseInt(stageSelect.value)) { name.textContent = s.name + " (you)"; }

      if (s.status == "passed") {
        badge.className = "badge";
        badge.textContent = "Passed";
      } else if (s.status == "arrived") {
        li.className = "arrived";
        badge.className = "badge arrived";
        badge.textContent = "Arrived";
      } else if (s.status == "next") {
        li.className = "next";
        badge.className = "badge next";
        badge.textContent = "Next";
      } else {
        badge.className = "badge";
        badge.textContent = "Upcoming";
      }
      li.appendChild(name);
      li.appendChild(badge);
      ul.appendChild(li);
    }
  }

  function showQueue(data) {
    var note = document.getElementById("queue-note");
    if (data.queue == null) {
      note.textContent = "Queue at your stage: no information yet";
    } else {
      note.textContent = "Queue at your stage: " + data.queue.queue_count +
        " matatus waiting (updated " + data.queue.ago + " min ago)";
    }
  }

  function showHistory(data) {
    var body = document.getElementById("history-body");
    body.innerHTML = "";

    for (var i = 0; i < data.history.length; i++) {
      var h = data.history[i];
      var source = h.source.charAt(0).toUpperCase() + h.source.slice(1);
      var cells = [h.stage, h.time, source];

      var row = document.createElement("tr");
      for (var c = 0; c < cells.length; c++) {
        var td = document.createElement("td");
        td.textContent = cells[c];
        row.appendChild(td);
      }
      body.appendChild(row);
    }
  }

  // ask PHP for fresh data and show it
  function refreshAll() {
    var url = "dashboard.php?route=" + routeSelect.value + "&stage=" + stageSelect.value;

    callApi(url, null, function (data) {
      if (!data.ok) {
        // no network: show the last saved ETA if we have one
        var saved = JSON.parse(localStorage.getItem("lastEta"));
        if (saved != null) {
          showMessage(statusBar, "Could not reach the server. Showing the last saved ETA for " +
            saved.stage + " (" + formatTime(saved.time) + ").", "error");
          document.getElementById("eta-time").textContent = saved.text;
        } else {
          showMessage(statusBar, data.error, "error");
        }
        return;
      }
      hideMessage(statusBar);
      showEta(data);
      showStageList(data);
      showQueue(data);
      showHistory(data);
    });
  }

  // ----- events -----
  routeSelect.addEventListener("change", function () {
    fillStages();
    refreshAll();
  });

  stageSelect.addEventListener("change", refreshAll);

  form.addEventListener("submit", function (event) {
    event.preventDefault();      // stop the page from reloading
    refreshAll();
  });

  // search: type part of a stage name and the dropdowns jump to it
  searchBox.addEventListener("input", function () {
    var text = searchBox.value.toLowerCase().trim();
    if (text == "") { hideMessage(statusBar); return; }

    for (var id in routes) {
      var list = routes[id].stages;
      for (var i = 0; i < list.length; i++) {
        if (list[i].toLowerCase().indexOf(text) != -1) {
          routeSelect.value = id;
          fillStages();
          stageSelect.value = i;
          refreshAll();
          return;
        }
      }
    }
    showMessage(statusBar, "No stage found for \"" + searchBox.value + "\"", "error");
  });

  // report a departure
  reportBtn.addEventListener("click", function () {
    var msg = document.getElementById("report-msg");
    var data = { route: routeSelect.value, stage: stageSelect.value };

    callApi("report.php", data, function (result) {
      if (!result.ok) {
        showMessage(msg, result.error, "error");
      } else if (result.status == "valid") {
        showMessage(msg, "Thank you! Your report was added.", "success");
      } else {
        showMessage(msg, "Report not counted: " + result.reason + ".", "error");
      }
      refreshAll();
    });
  });

  // start
  loadRoutes(function (worked) {
    if (!worked) {
      showMessage(statusBar, "Could not load the routes. Is the server running?", "error");
      return;
    }
    fillRoutes();
    fillStages();
    stageSelect.selectedIndex = 3;     // start on the 4th stage so the ETA has something to show
    refreshAll();
    setInterval(refreshAll, 30000);    // refresh every 30 seconds
  });
}


// ---------- 5. MARSHAL PAGE (marshal.html) ----------
function startMarshalPage() {
  var stageSelect = document.getElementById("mstage");
  var logBtn = document.getElementById("log-btn");
  var queueForm = document.getElementById("queue-form");
  var msg = document.getElementById("marshal-msg");

  // each option holds "route:stageNumber", for example "33:4"
  function fillStages() {
    stageSelect.innerHTML = "";
    for (var id in routes) {
      var group = document.createElement("optgroup");
      group.label = routes[id].name;
      for (var i = 0; i < routes[id].stages.length; i++) {
        var op = document.createElement("option");
        op.value = id + ":" + i;
        op.textContent = routes[id].stages[i];
        group.appendChild(op);
      }
      stageSelect.appendChild(group);
    }
  }

  function chosen() {
    var parts = stageSelect.value.split(":");
    return { route: parts[0], stage: parts[1] };
  }

  logBtn.addEventListener("click", function () {
    callApi("marshal_log.php", chosen(), function (result) {
      if (!result.ok) {
        showMessage(msg, result.error, "error");
      } else if (result.status == "valid") {
        showMessage(msg, "Departure logged at " + stageSelect.selectedOptions[0].textContent + ".", "success");
      } else {
        showMessage(msg, "Not counted: " + result.reason + ".", "error");
      }
    });
  });

  queueForm.addEventListener("submit", function (event) {
    event.preventDefault();
    var number = document.getElementById("queue").value;

    // simple check here (PHP checks again)
    if (number == "" || isNaN(number) || number < 0 || number != Math.floor(number)) {
      showMessage(msg, "Please enter a whole number of 0 or more.", "error");
      return;
    }
    var data = chosen();
    data.count = number;

    callApi("queue_save.php", data, function (result) {
      if (result.ok) {
        showMessage(msg, "Queue saved: " + number + " matatus.", "success");
      } else {
        showMessage(msg, result.error, "error");
      }
    });
  });

  // start: only marshals (and admins) can use this page
  requireLogin(["marshal", "admin"], function () {
    loadRoutes(function () { fillStages(); });
  });
}


// ---------- 6. ANALYTICS PAGE (analytics.html) ----------
function startAnalyticsPage() {
  var form = document.getElementById("report-form");
  var msg = document.getElementById("analytics-msg");
  var fromBox = document.getElementById("from");
  var toBox = document.getElementById("to");

  // line chart: one point for each day that has data
  function drawChart(chart) {
    var svgNS = "http://www.w3.org/2000/svg";
    var dots = document.getElementById("chart-dots");
    var labels = document.getElementById("chart-labels");
    dots.innerHTML = "";
    labels.innerHTML = "";

    var points = "";
    for (var i = 0; i < chart.length; i++) {
      var x = 40 + i * 66;

      var label = document.createElementNS(svgNS, "text");
      label.setAttribute("x", x - 14);
      label.setAttribute("y", 135);
      label.textContent = chart[i].label;
      labels.appendChild(label);

      if (chart[i].value == null) { continue; }      // no data that day
      var y = 120 - chart[i].value * 2.5;
      points = points + x + "," + y + " ";

      var dot = document.createElementNS(svgNS, "circle");
      dot.setAttribute("cx", x);
      dot.setAttribute("cy", y);
      dot.setAttribute("r", 4);
      dots.appendChild(dot);
    }
    document.getElementById("chart-line").setAttribute("points", points);
  }

  // ask PHP for the numbers. generate = "1" also saves a row in performance_log
  function loadStats(generate) {
    var url = "analytics.php?from=" + fromBox.value + "&to=" + toBox.value + "&generate=" + generate;

    callApi(url, null, function (data) {
      if (!data.ok) {
        showMessage(msg, data.error, "error");
        return;
      }
      document.getElementById("stat-trips").textContent = data.trips;
      document.getElementById("stat-flagged").textContent = data.flagged;
      document.getElementById("stat-avg").textContent = (data.average == null) ? "-" : data.average + " min";
      drawChart(data.chart);
      if (generate == "1") { showMessage(msg, "Report generated.", "success"); }
    });
  }

  // flagged reports table with Override buttons
  function loadFlagged() {
    callApi("flagged.php", null, function (data) {
      var body = document.getElementById("flagged-body");
      body.innerHTML = "";

      if (!data.ok || data.flagged.length == 0) {
        var empty = document.createElement("tr");
        var cell = document.createElement("td");
        cell.colSpan = 4;
        cell.textContent = "No flagged reports.";
        empty.appendChild(cell);
        body.appendChild(empty);
        return;
      }

      for (var i = 0; i < data.flagged.length; i++) {
        var f = data.flagged[i];
        var row = document.createElement("tr");

        var td1 = document.createElement("td");
        td1.textContent = f.stage + " (Route " + f.route_number + ")";
        var td2 = document.createElement("td");
        td2.textContent = f.time;

        var td3 = document.createElement("td");
        var badge = document.createElement("span");
        badge.className = "badge flagged";
        badge.textContent = f.reason;
        td3.appendChild(badge);

        var td4 = document.createElement("td");
        var btn = document.createElement("button");
        btn.type = "button";
        btn.className = "btn btn-outline";
        btn.textContent = "Override";
        btn.setAttribute("data-id", f.report_id);     // remember which report this button is for
        btn.addEventListener("click", overrideReport);
        td4.appendChild(btn);

        row.appendChild(td1);
        row.appendChild(td2);
        row.appendChild(td3);
        row.appendChild(td4);
        body.appendChild(row);
      }
    });
  }

  // admin decides the report was actually correct
  function overrideReport(event) {
    var id = event.target.getAttribute("data-id");
    callApi("override.php", { report_id: id }, function (result) {
      if (result.ok) {
        showMessage(msg, "Report accepted. It is now counted as valid.", "success");
        loadFlagged();
        loadStats("0");
      } else {
        showMessage(msg, result.error, "error");
      }
    });
  }

  form.addEventListener("submit", function (event) {
    event.preventDefault();
    if (fromBox.value != "" && toBox.value != "" && fromBox.value > toBox.value) {
      showMessage(msg, "The 'From' date must be before the 'To' date.", "error");
      return;
    }
    loadStats("1");
  });

  // start: admins only
  requireLogin(["admin"], function () {
    loadFlagged();
    loadStats("0");
  });
}


// ---------- 7. UPLOAD PAGE (admin-upload.html) ----------
function startUploadPage() {
  var form = document.getElementById("upload-form");
  var fileBox = document.getElementById("report_file");
  var msg = document.getElementById("upload-msg");
  var body = document.getElementById("uploads-body");

  function addRow(fileName, status) {
    var today = new Date();
    var date = pad(today.getDate()) + "/" + pad(today.getMonth() + 1) + "/" + today.getFullYear();
    var cells = [fileName, "Admin", date];

    var row = document.createElement("tr");
    for (var i = 0; i < cells.length; i++) {
      var td = document.createElement("td");
      td.textContent = cells[i];
      row.appendChild(td);
    }
    var last = document.createElement("td");
    var badge = document.createElement("span");
    badge.className = "badge valid";
    badge.textContent = status;
    last.appendChild(badge);
    row.appendChild(last);
    body.insertBefore(row, body.firstChild);   // newest at the top
  }

  form.addEventListener("submit", function (event) {
    event.preventDefault();

    if (fileBox.files.length == 0) {
      showMessage(msg, "Please choose a file first.", "error");
      return;
    }
    var file = fileBox.files[0];

    if (!file.name.toLowerCase().endsWith(".csv")) {
      showMessage(msg, "Only CSV files are supported. In Excel use Save As, CSV.", "error");
      return;
    }
    if (file.size > 2 * 1024 * 1024) {
      showMessage(msg, "The file is too big (maximum 2 MB).", "error");
      return;
    }

    callApi("upload.php", new FormData(form), function (result) {
      if (!result.ok) {
        showMessage(msg, result.error, "error");
        return;
      }
      addRow(file.name, result.added + " added, " + result.skipped + " skipped");
      var text = file.name + " uploaded: " + result.added + " rows added, " + result.skipped + " skipped.";
      if (result.problems.length > 0) { text = text + " " + result.problems.join(" "); }
      showMessage(msg, text, "success");
      form.reset();
    });
  });

  // start: admins only
  requireLogin(["admin"], function () { });
}


// ---------- 8. LOGIN PAGE (login.html) ----------
function startLoginPage() {
  var form = document.getElementById("login-form");
  var error = document.getElementById("login-error");

  form.addEventListener("submit", function (event) {
    event.preventDefault();
    var data = {
      username: document.getElementById("username").value.trim(),
      password: document.getElementById("password").value
    };

    if (data.username == "" || data.password == "") {
      showMessage(error, "Please enter your username and password.", "error");
      return;
    }

    callApi("login.php", data, function (result) {
      if (result.ok) {
        window.location.href = result.redirect;    // PHP says where to go
      } else {
        showMessage(error, result.error, "error");
      }
    });
  });
}


// ---------- 9. PROFILE PAGE (profile.html) ----------
function startProfilePage() {
  var form = document.getElementById("profile-form");
  var msg = document.getElementById("profile-msg");

  form.addEventListener("submit", function (event) {
    event.preventDefault();
    var data = {
      name: document.getElementById("name").value.trim(),
      current: document.getElementById("current").value,
      newpass: document.getElementById("newpass").value
    };

    if (data.name == "") {
      showMessage(msg, "Username cannot be empty.", "error");
      return;
    }
    if (data.newpass != "" && data.newpass.length < 8) {
      showMessage(msg, "The new password must be at least 8 characters.", "error");
      return;
    }

    callApi("profile.php", data, function (result) {
      if (result.ok) {
        showMessage(msg, "Changes saved.", "success");
        document.getElementById("current").value = "";
        document.getElementById("newpass").value = "";
      } else {
        showMessage(msg, result.error, "error");
      }
    });
  });

  // start: show the logged-in username
  requireLogin(["marshal", "admin"], function (me) {
    document.getElementById("name").value = me.username;
  });
}


// ---------- 9b. MANAGE ROUTES PAGE (manage-routes.html) ----------
function startManagePage() {
  var msg = document.getElementById("manage-msg");
  var picker = document.getElementById("route-picker");
  var routeForm = document.getElementById("route-form");
  var stageEditForm = document.getElementById("stage-edit-form");
  var addForm = document.getElementById("add-stage-form");
  var body = document.getElementById("stages-body");

  var allRoutes = [];      // the routes (with their stages) from PHP
  var currentId = null;    // id of the route chosen in the dropdown

  // find the chosen route in the list
  function currentRoute() {
    for (var i = 0; i < allRoutes.length; i++) {
      if (allRoutes[i].id == currentId) { return allRoutes[i]; }
    }
    return null;
  }

  // get everything from PHP, then show it. keepId = the route to select afterwards
  function loadData(keepId) {
    callApi("admin_routes.php", null, function (result) {
      if (!result.ok) {
        showMessage(msg, result.error, "error");
        return;
      }
      allRoutes = result.routes;

      picker.innerHTML = "";
      for (var i = 0; i < allRoutes.length; i++) {
        var op = document.createElement("option");
        op.value = allRoutes[i].id;
        op.textContent = allRoutes[i].name;
        picker.appendChild(op);
      }

      if (keepId != null) { picker.value = keepId; }
      currentId = picker.value;
      showStages();
    });
  }

  // draw the stages table and fill the "where does it go" dropdown
  function showStages() {
    var route = currentRoute();
    body.innerHTML = "";
    var after = document.getElementById("as-after");
    after.innerHTML = "";

    if (route == null) {
      document.getElementById("stages-title").textContent = "Stages";
      return;
    }
    document.getElementById("stages-title").textContent = "Stages on " + route.name;

    if (route.stages.length == 0) {
      var empty = document.createElement("tr");
      var cell = document.createElement("td");
      cell.colSpan = 5;
      cell.textContent = "This route has no stages yet. Add the first one below.";
      empty.appendChild(cell);
      body.appendChild(empty);
    }

    var endOption = document.createElement("option");
    endOption.value = "end";
    endOption.textContent = "At the end of the route";
    after.appendChild(endOption);

    for (var i = 0; i < route.stages.length; i++) {
      var s = route.stages[i];

      // dropdown option
      var op = document.createElement("option");
      op.value = s.id;
      op.textContent = "After " + s.name;
      after.appendChild(op);

      // table row
      var row = document.createElement("tr");
      var texts = [
        s.sequence,
        s.name,
        (s.baseline == null) ? "-" : s.baseline,
        (s.limit == null) ? "-" : s.limit
      ];
      for (var c = 0; c < texts.length; c++) {
        var td = document.createElement("td");
        td.textContent = texts[c];
        row.appendChild(td);
      }

      var actions = document.createElement("td");
      actions.className = "actions-cell";
      actions.appendChild(makeButton("Up", "up", s.id, i == 0, ""));
      actions.appendChild(makeButton("Down", "down", s.id, i == route.stages.length - 1, ""));
      actions.appendChild(makeButton("Edit", "edit", s.id, false, ""));
      var cantDelete = s.reports > 0;
      var tip = cantDelete ? "This stage has reports, so it cannot be deleted." : "";
      actions.appendChild(makeButton("Delete", "delete", s.id, cantDelete, tip));
      row.appendChild(actions);
      body.appendChild(row);
    }
  }

  // one small button for the Actions column
  function makeButton(text, action, id, disabled, tip) {
    var btn = document.createElement("button");
    btn.type = "button";
    btn.textContent = text;
    btn.className = "btn btn-small " + (action == "delete" ? "btn-danger" : "btn-outline");
    btn.disabled = disabled;
    if (tip != "") { btn.title = tip; }
    btn.setAttribute("data-action", action);
    btn.setAttribute("data-id", id);
    btn.addEventListener("click", rowButtonClicked);
    return btn;
  }

  // find a stage of the chosen route by its id
  function findStageById(id) {
    var route = currentRoute();
    for (var i = 0; i < route.stages.length; i++) {
      if (route.stages[i].id == id) { return route.stages[i]; }
    }
    return null;
  }

  // Up / Down / Edit / Delete buttons
  function rowButtonClicked(event) {
    var action = event.target.getAttribute("data-action");
    var id = event.target.getAttribute("data-id");
    var stage = findStageById(id);
    hideMessage(msg);

    if (action == "up" || action == "down") {
      callApi("stage_move.php", { stage_id: id, direction: action }, function (result) {
        if (result.ok) {
          showMessage(msg, "Order changed. Please check the travel times.", "success");
          loadData(currentId);
        } else {
          showMessage(msg, result.error, "error");
        }
      });
    }

    if (action == "edit") {
      var route = currentRoute();
      var isLast = (stage.sequence == route.stages.length);
      document.getElementById("se-id").value = stage.id;
      document.getElementById("se-name").value = stage.name;
      document.getElementById("se-baseline").value = (stage.baseline == null) ? "" : stage.baseline;
      document.getElementById("se-limit").value = (stage.limit == null) ? "" : stage.limit;
      document.getElementById("se-times").style.display = isLast ? "none" : "flex";   // the last stage has no next stage
      stageEditForm.style.display = "block";
      stageEditForm.scrollIntoView();
    }

    if (action == "delete") {
      if (!confirm("Delete the stage " + stage.name + "?")) { return; }
      callApi("stage_delete.php", { stage_id: id }, function (result) {
        if (result.ok) {
          showMessage(msg, "Stage deleted.", "success");
          loadData(currentId);
        } else {
          showMessage(msg, result.error, "error");
        }
      });
    }
  }

  // ----- route picker and route form -----
  picker.addEventListener("change", function () {
    currentId = picker.value;
    stageEditForm.style.display = "none";
    showStages();
  });

  function openRouteForm(route) {
    document.getElementById("route-form-title").textContent = (route == null) ? "Add a new route" : "Edit this route";
    document.getElementById("rf-id").value = (route == null) ? "" : route.id;
    document.getElementById("rf-number").value = (route == null) ? "" : route.number;
    document.getElementById("rf-name").value = (route == null) ? "" : route.name;
    document.getElementById("rf-details").value = (route == null || route.details == null) ? "" : route.details;
    routeForm.style.display = "block";
    routeForm.scrollIntoView();
  }

  document.getElementById("new-route-btn").addEventListener("click", function () {
    hideMessage(msg);
    openRouteForm(null);
  });

  document.getElementById("edit-route-btn").addEventListener("click", function () {
    hideMessage(msg);
    if (currentRoute() != null) { openRouteForm(currentRoute()); }
  });

  document.getElementById("route-cancel").addEventListener("click", function () {
    routeForm.style.display = "none";
  });

  routeForm.addEventListener("submit", function (event) {
    event.preventDefault();
    var data = {
      route_id: document.getElementById("rf-id").value,
      number: document.getElementById("rf-number").value.trim(),
      name: document.getElementById("rf-name").value.trim(),
      details: document.getElementById("rf-details").value.trim()
    };
    if (data.number == "" || data.name == "") {
      showMessage(msg, "Please enter the route number and name.", "error");
      return;
    }
    callApi("route_save.php", data, function (result) {
      if (result.ok) {
        showMessage(msg, "Route saved.", "success");
        routeForm.style.display = "none";
        loadData(result.route_id);
      } else {
        showMessage(msg, result.error, "error");
      }
    });
  });

  // ----- edit a stage -----
  document.getElementById("stage-edit-cancel").addEventListener("click", function () {
    stageEditForm.style.display = "none";
  });

  stageEditForm.addEventListener("submit", function (event) {
    event.preventDefault();
    var data = {
      stage_id: document.getElementById("se-id").value,
      name: document.getElementById("se-name").value.trim(),
      baseline: document.getElementById("se-baseline").value,
      limit: document.getElementById("se-limit").value
    };
    if (data.name == "") {
      showMessage(msg, "Please enter a stage name.", "error");
      return;
    }
    // the times only matter when the stage has a next stage (the boxes are hidden for the last one)
    if (document.getElementById("se-times").style.display != "none") {
      if (parseFloat(data.limit) < parseFloat(data.baseline)) {
        showMessage(msg, "The longest time cannot be less than the normal time.", "error");
        return;
      }
    }
    callApi("stage_save.php", data, function (result) {
      if (result.ok) {
        showMessage(msg, "Stage saved.", "success");
        stageEditForm.style.display = "none";
        loadData(currentId);
      } else {
        showMessage(msg, result.error, "error");
      }
    });
  });

  // ----- add a stage -----
  addForm.addEventListener("submit", function (event) {
    event.preventDefault();
    var data = {
      route_id: currentId,
      name: document.getElementById("as-name").value.trim(),
      after: document.getElementById("as-after").value,
      baseline: document.getElementById("as-baseline").value,
      limit: document.getElementById("as-limit").value
    };
    if (data.route_id == null || data.route_id == "") {
      showMessage(msg, "Choose or add a route first.", "error");
      return;
    }
    if (data.name == "") {
      showMessage(msg, "Please enter a stage name.", "error");
      return;
    }
    if (parseFloat(data.limit) < parseFloat(data.baseline)) {
      showMessage(msg, "The longest time cannot be less than the normal time.", "error");
      return;
    }
    callApi("stage_save.php", data, function (result) {
      if (result.ok) {
        showMessage(msg, "Stage added.", "success");
        addForm.reset();
        loadData(currentId);
      } else {
        showMessage(msg, result.error, "error");
      }
    });
  });

  // start: admins only
  requireLogin(["admin"], function () { loadData(null); });
}


// ---------- 10. START: run the code for the page we are on ----------
var page = document.body.getAttribute("data-page");

if (page == "commuter") { startCommuterPage(); }
if (page == "marshal") { startMarshalPage(); }
if (page == "analytics") { startAnalyticsPage(); }
if (page == "upload") { startUploadPage(); }
if (page == "login") { startLoginPage(); }
if (page == "profile") { startProfilePage(); }
if (page == "manage") { startManagePage(); }
