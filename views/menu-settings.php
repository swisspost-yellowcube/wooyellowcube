<h1><?php _e('WooYellowCube', 'wooyellowcube'); ?></h1>
<form action="" method="post">
<div class="wooyellowcube-overflow">
  <div class="wooyellowcube-inline">
  <h2><?php _e('Personal information', 'wooyellowcube'); ?></h2>

  <!-- Setter -->
  <p>
    <label for="setter"><?php _e('Sender', 'wooyellowcube'); ?></label>
    <input type="text" name="setter" id="setter" value="<?php echo get_option('wooyellowcube_setter'); ?>" />
  </p>

  <!-- Receiver -->
  <p>
    <label for="receiver"><?php _e('Receiver', 'wooyellowcube'); ?></label>
    <input type="text" name="receiver" id="receiver" value="<?php echo get_option('wooyellowcube_receiver'); ?>" />
  </p>

  <!-- DepositorNo -->
  <p>
    <label for="depositorNo"><?php _e('DepositorNo', 'wooyellowcube')?></label>
    <input type="text" name="depositorNo" id="depositorNo" value="<?php echo get_option('wooyellowcube_depositorNo'); ?>" />
  </p>

  <!-- PartnerNo -->
  <p>
    <label for="partnerNo"><?php _e('PartnerNo', 'wooyellowcube'); ?></label>
    <input type="text" name="partnerNo" id="partnerNo" value="<?php echo get_option('wooyellowcube_partnerNo'); ?>" />
  </p>

  <!-- Plant -->
  <p>
    <label for="plant"><?php _e('Plant', 'wooyellowcube'); ?></label>
    <input type="text" name="plant" id="plant" value="<?php echo get_option('wooyellowcube_plant'); ?>" />
  </p>

</div>
<div class="wooyellowcube-inline">
  <h2><?php _e('Technical information', 'wooyellowcube'); ?></h2>
  <!-- SOAP Method -->
  <p>
    <label for="yellowcubeSOAPUrl"><?php _e('SOAP url', 'wooyellowcube'); ?></label>
    <select name="yellowcubeSOAPUrl" id="yellowcubeSOAPUrl">
      <option value="1" <?php if(get_option('wooyellowcube_yellowcubeSOAPUrl') == 1) echo 'selected="selected"'; ?>>https://service-test.swisspost.ch/apache/yellowcube-test/?wsdl</option>
      <option value="2" <?php if(get_option('wooyellowcube_yellowcubeSOAPUrl') == 2) echo 'selected="selected"'; ?>>https://service-test.swisspost.ch/apache/yellowcube-int/?wsdl</option>
      <option value="3" <?php if(get_option('wooyellowcube_yellowcubeSOAPUrl') == 3) echo 'selected="selected"'; ?>>https://service.swisspost.ch/apache/yellowcube/?wsdl</option>
    </select>
  </p>

  <!-- Operating mode -->
  <p>
    <label for="operatingMode"><?php _e('Operating mode', 'wooyellowcube'); ?></label>
    <select name="operatingMode" id="operatingMode">
      <option value="D" <?php if(get_option('wooyellowcube_operatingMode') == 'D') echo 'selected="selected"'; ?>>Development</option>
      <option value="T" <?php if(get_option('wooyellowcube_operatingMode') == 'T') echo 'selected="selected"'; ?>>Testing</option>
      <option value="P" <?php if(get_option('wooyellowcube_operatingMode') == 'P') echo 'selected="selected"'; ?>>Production</option>
    </select>
  </p>

  <!-- Authentification -->
  <p>
    <label for="authentification"><?php _e('Authentification', 'wooyellowcube'); ?></label>
    <select name="authentification" id="authentification" class="wooyellowcube_authentification">
      <option value="0" <?php if(get_option('wooyellowcube_authentification') == 0) echo 'selected="selected"'; ?>>No</option>
      <option value="1" <?php if(get_option('wooyellowcube_authentification') == 1) echo 'selected="selected"'; ?>>Yes</option>
    </select>
  </p>

  <p>
    <label for="authentificationFile"><?php _e('Authentification file', 'wooyellowcube'); ?></label>
    <input type="text" name="authentificationFile" id="authentificationFile" value="<?php echo get_option('wooyellowcube_authentificationFile'); ?>" <?php if(get_option('wooyellowcube_authentification') == 0) echo 'disabled="disabled"'; ?> size="35" />
  </p>

  <h2><?php _e('Logs', 'wooyellowcube');?></h2>
  <p>
	  <label for="logs"><?php _e('Day before logs are removed from database', 'wooyellowcube'); ?></label>
	  <br />
	  <input type="text" name="logs" id="logs" value="<?php echo get_option('wooyellowcube_logs'); ?>" />
  </p>
    <p>
        <label for="logsDebug"><?php _e('Debug Request Log', 'wooyellowcube'); ?></label>
        <select name="logsDebug" id="logsDebug">
            <option value="0" <?php if(get_option('wooyellowcube_logsDebug') == 0) echo 'selected="selected"'; ?>>No</option>
            <option value="1" <?php if(get_option('wooyellowcube_logsDebug') == 1) echo 'selected="selected"'; ?>>Yes</option>
        </select>
    </p>






</div>
</div>
    <p>
        <input type="submit" name="wooyellowcube-settings" value="<?=__('Save information', 'wooyellowcube')?>" class="button" />
    </p>
</form>
