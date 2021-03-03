<title>Extension Status</title>
<style>
table {
  width: 100%;
}

tr:hover {
  background-color: #f5f5f5;
}

th {
  height: 50px;
  text-align: left;
  background-color: #4CAF50;
  color: black;
}
th, td {
  padding: 15px;
  border-bottom: 1px solid #ddd;
  text-align: left;
}
</style>
<div style="overflow-x:auto;">
<table>
  <thead>
    <tr>
      <th>Extension</th>
<?php
      if ($showuri) { echo "      <th>URI</th>"; }
?>
      <th>Brand</th>
      <th>Model</th>
      <th>Firmware</th>
      <th>Status</th>
      <th>Response Time</th>
      <th>Known Device IPs</th>
      <th>Registration Expires</th>
    </tr>
  </thead>
  <tbody>
