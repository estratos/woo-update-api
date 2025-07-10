<?php
$status = $this->error_manager->get_status();
?>
<div class="woo-api-status <?php echo $status['health']; ?>">
    <span class="dashicons <?php echo $this->get_status_icon($status); ?>"></span>
    <div class="status-content">
        <h4><?php echo $this->get_status_title($status); ?></h4>
        <p><?php echo $this->get_status_description($status); ?></p>
    </div>
</div>